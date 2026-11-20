#!/usr/bin/env python3
"""URL -> extractor matching for gallery-dl and yt-dlp.

"resolve" and "catalog" only inspect the extractor classes bundled with the installed tools: no network
access, no extraction. "probe" is the network fallback for URLs "resolve" found nothing for.
Called by App\\Service\\Site\\ExtractorMatcher with an argv array (never a shell).
JSON results are written to stdout; debug diagnostics are written to stderr.

Usage:
    resolve_url.py resolve <url>
        Offline. If nothing matches the URL, retries with the www. and parent-domain variants of its host
        (fr.vimeo.com -> www.fr.vimeo.com, vimeo.com, www.vimeo.com): extractor patterns often omit regional subdomains.
        If no URL matches (e.g. a front page: patterns describe media pages), returns the extractors whose known
        URLs (gallery-dl roots and examples, yt-dlp test URLs) are on the host or one of its variants.
        -> {"extractors": [{"tool": "gallery-dl", "key": "twitter", "family": "twitter"}, ...]}
    resolve_url.py probe <url>
        Network. Fetches the page with yt-dlp's generic extractor (embedded players, video tags, ...).
        Refuses non-http(s) URLs and hosts resolving to non-public addresses. A supported page yields one
        extractor per registrable domain, as SiteExtractor keys are unique across sites:
        -> {"extractors": [{"tool": "yt-dlp", "key": "Generic:example.com", "family": "example.com"}]}
    resolve_url.py catalog <json: ["twitter", "youtube"]>
        All extractors of the given families, with the hosts known from their roots, examples and tests:
        -> {"extractors": [{"tool": "yt-dlp", "key": "Twitter", "family": "twitter", "hosts": ["x.com", ...]}, ...]}
"""

import ipaddress
import json
import logging
import socket
import sys
from urllib.parse import unquote, urlsplit


logger = logging.getLogger('resolve_url')

PROBE_SOCKET_TIMEOUT = 10

# Second-level labels of common two-label public suffixes (example.co.uk, example.com.au). Only used to
# group generically probed hosts by site: a miss merely splits a site in two.
_SECOND_LEVEL_SUFFIXES = {'ac', 'co', 'com', 'edu', 'gov', 'ne', 'net', 'or', 'org'}


def _host(url):
    try:
        host = urlsplit(url).hostname
    except ValueError:
        return None
    return host.lower().rstrip('.') if host else None


def _is_ip(host):
    try:
        ipaddress.ip_address(host)
    except ValueError:
        return False
    return True


def _registrable_domain(host):
    labels = host.split('.')
    if len(labels) > 2 and len(labels[-1]) == 2 and labels[-2] in _SECOND_LEVEL_SUFFIXES:
        return '.'.join(labels[-3:])
    return '.'.join(labels[-2:])


def _host_variants(host):
    """www. and parent-domain variants of a host, closest first; never the host itself or a bare TLD."""
    labels = host.split('.')
    variants = []
    if labels[0] != 'www' and len(labels) >= 2:
        variants.append('www.' + host)
    for i in range(1, len(labels) - 1):
        parent = '.'.join(labels[i:])
        variants += [parent, 'www.' + parent]
    return [v for i, v in enumerate(variants) if v != host and v not in variants[:i]]


def _with_host(url, host):
    parts = urlsplit(url)
    netloc = host if parts.port is None else f'{host}:{parts.port}'
    if '@' in parts.netloc:
        netloc = parts.netloc.rsplit('@', 1)[0] + '@' + netloc
    return parts._replace(netloc=netloc).geturl()


def _gallery_dl_classes():
    from gallery_dl import extractor

    return extractor._list_classes()


def _yt_dlp_classes():
    from yt_dlp.extractor import gen_extractor_classes

    return [c for c in gen_extractor_classes() if c.ie_key() != 'Generic']


def _yt_dlp_family(cls):
    # yt_dlp.extractor.youtube._video -> youtube; lazy extractors keep the real module in _module
    parts = getattr(cls, '_module', cls.__module__).split('.')
    return parts[2] if len(parts) > 2 else cls.ie_key().lower()


def resolve(url):
    host = _host(url)
    logger.debug('Resolving extractors for host: %s', host)
    extractors = _match(url)

    if not extractors and host and not _is_ip(host):
        for variant in _host_variants(host):
            logger.debug('Retrying with host variant: %s', variant)
            extractors = _match(_with_host(url, variant))
            if extractors:
                logger.debug('Host variant %s matched', variant)
                break

    if not extractors and host and not _is_ip(host):
        extractors = _match_host(host)

    logger.debug('Resolution completed with %d extractors', len(extractors))
    return {'extractors': extractors}


def _match_host(host):
    """Extractors whose known URLs are on the host or one of its variants (front pages, other non-media pages)."""
    logger.debug('No URL match; looking up extractors by host')
    index = {}

    try:
        for cls in _gallery_dl_classes():
            for url in (getattr(cls, 'root', None), getattr(cls, 'example', None)):
                if isinstance(url, str) and (known := _host(url)):
                    index.setdefault(known, {})[('gallery-dl', cls.category)] = cls.category
    except ImportError:
        logger.debug('gallery-dl is not available')

    try:
        for cls in _yt_dlp_classes():
            for test in cls.get_testcases(include_onlymatching=True):
                url = test.get('url')
                # Only URLs the extractor itself accepts: tests may start from other hosts
                if isinstance(url, str) and (known := _host(url)) and cls.suitable(url):
                    index.setdefault(known, {})[('yt-dlp', cls.ie_key())] = _yt_dlp_family(cls)
    except ImportError:
        logger.debug('yt-dlp is not available')

    for candidate in [host, *_host_variants(host)]:
        if candidate in index:
            logger.debug('Host %s is known to %d extractors', candidate, len(index[candidate]))
            return [{'tool': tool, 'key': key, 'family': family} for (tool, key), family in sorted(index[candidate].items())]

    logger.debug('Host is not known to any extractor')
    return []


def _match(url):
    extractors = []

    try:
        from gallery_dl import extractor

        match = extractor.find(url)
        if match is not None:
            extractors.append({'tool': 'gallery-dl', 'key': match.category, 'family': match.category})
            logger.debug('gallery-dl matched extractor: %s', match.category)
        else:
            logger.debug('gallery-dl found no matching extractor')
    except ImportError:
        logger.debug('gallery-dl is not available')

    try:
        seen = set()
        for cls in _yt_dlp_classes():
            if cls.suitable(url) and cls.ie_key() not in seen:
                seen.add(cls.ie_key())
                family = _yt_dlp_family(cls)
                extractors.append({'tool': 'yt-dlp', 'key': cls.ie_key(), 'family': family})
                logger.debug('yt-dlp matched extractor: %s (family: %s)', cls.ie_key(), family)
        logger.debug('yt-dlp matched %d extractors', len(seen))
    except ImportError:
        logger.debug('yt-dlp is not available')

    return extractors


def _public_host_error(url):
    """Why the URL must not be fetched from the server, or None. Redirects are not re-checked."""
    try:
        parts = urlsplit(url)
        port = parts.port
    except ValueError:
        return 'invalid URL'
    if parts.scheme not in ('http', 'https'):
        return 'unsupported scheme'
    host = _host(url)
    if not host:
        return 'no host'
    try:
        addresses = {info[4][0] for info in socket.getaddrinfo(host, port or 443, proto=socket.IPPROTO_TCP)}
    except (OSError, UnicodeError):
        return 'host does not resolve'
    for address in addresses:
        if not ipaddress.ip_address(address.split('%', 1)[0]).is_global:
            return 'host resolves to a non-public address'
    return None


class _YtDlpLogger:
    """Forwards yt-dlp's output as debug diagnostics, without the URL (its query may hold tokens)."""

    def __init__(self, url, host):
        # The generic extractor also prints the last path segment, query included, as the video ID
        last_segment = url.rstrip('/').rsplit('/', 1)[-1]
        self._secrets = sorted({url, last_segment, unquote(last_segment)} - {'', host}, key=len, reverse=True)
        self._placeholder = f'<{host} URL>'

    def debug(self, message):
        logger.debug('yt-dlp: %s', self.redact(message))

    def redact(self, message):
        message = str(message)
        for secret in self._secrets:
            message = message.replace(secret, self._placeholder)
        return message

    info = debug
    warning = debug
    error = debug


def probe(url):
    host = _host(url)
    logger.debug('Probing generic support for host: %s', host)

    error = _public_host_error(url)
    if error is not None:
        logger.debug('Refusing to probe: %s', error)
        return {'extractors': []}

    try:
        from yt_dlp import YoutubeDL
        from yt_dlp.utils import DownloadError
    except ImportError:
        logger.debug('yt-dlp is not available')
        return {'extractors': []}

    ytdlp_logger = _YtDlpLogger(url, host)
    options = {
        'force_generic_extractor': True,
        'logger': ytdlp_logger,
        'noplaylist': True,
        'quiet': True,
        'skip_download': True,
        'socket_timeout': PROBE_SOCKET_TIMEOUT,
    }
    try:
        with YoutubeDL(options) as ydl:
            # process=False: only run the extractor, don't resolve formats or embedded URLs
            info = ydl.extract_info(url, download=False, process=False)
    except DownloadError as e:
        message = str(e).splitlines()[0] if str(e) else type(e).__name__
        logger.debug('Generic extractor found nothing: %s', ytdlp_logger.redact(message))
        return {'extractors': []}

    if not info:
        logger.debug('Generic extractor returned no result')
        return {'extractors': []}

    domain = _registrable_domain(host)
    logger.debug('Generic extractor supports the page (result type: %s, domain: %s)', info.get('_type', 'video'), domain)
    return {'extractors': [{'tool': 'yt-dlp', 'key': 'Generic:' + domain, 'family': domain}]}


def catalog(families):
    families = set(families)
    entries = {}
    logger.debug('Building extractor catalog for %d families', len(families))

    def add(tool, key, family, urls):
        entry = entries.setdefault((tool, key), {'tool': tool, 'key': key, 'family': family, 'hosts': set()})
        for url in urls:
            if isinstance(url, str) and (host := _host(url)):
                entry['hosts'].add(host)

    try:
        for cls in _gallery_dl_classes():
            if cls.category in families:
                add('gallery-dl', cls.category, cls.category, (getattr(cls, 'root', None), getattr(cls, 'example', None)))
        logger.debug('Catalog contains %d gallery-dl entries', len(entries))
    except ImportError:
        logger.debug('gallery-dl is not available')

    try:
        gallery_count = len(entries)
        for cls in _yt_dlp_classes():
            family = _yt_dlp_family(cls)
            if family in families:
                add('yt-dlp', cls.ie_key(), family, (t.get('url') for t in cls.get_testcases(include_onlymatching=True)))
        logger.debug('Catalog contains %d yt-dlp entries', len(entries) - gallery_count)
    except ImportError:
        logger.debug('yt-dlp is not available')

    logger.debug('Catalog completed with %d extractors', len(entries))
    return {'extractors': [dict(e, hosts=sorted(e['hosts'])) for e in entries.values()]}


def main(argv):
    if len(argv) != 3 or argv[1] not in ('resolve', 'probe', 'catalog'):
        print(__doc__, file=sys.stderr)
        return 2

    if argv[1] == 'resolve':
        output = resolve(argv[2])
    elif argv[1] == 'probe':
        output = probe(argv[2])
    else:
        output = catalog(json.loads(argv[2]))

    json.dump(output, sys.stdout)
    return 0


if __name__ == '__main__':
    handler = logging.StreamHandler(sys.stderr)
    handler.setFormatter(logging.Formatter('%(levelname)s: %(message)s'))
    logger.addHandler(handler)
    logger.setLevel(logging.DEBUG)
    logger.propagate = False
    sys.exit(main(sys.argv))
