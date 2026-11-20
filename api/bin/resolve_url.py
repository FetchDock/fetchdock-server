#!/usr/bin/env python3
"""Offline URL -> extractor matching for gallery-dl and yt-dlp.

Only inspects the extractor classes bundled with the installed tools: no network access,
no extraction. Called by App\\Service\\Site\\ExtractorMatcher with an argv array (never a shell).

Usage:
    resolve_url.py resolve <url>
        -> {"extractors": [{"tool": "gallery-dl", "key": "twitter", "family": "twitter"}, ...]}
    resolve_url.py catalog <json: ["twitter", "youtube"]>
        All extractors of the given families, with the hosts known from their roots, examples and tests:
        -> {"extractors": [{"tool": "yt-dlp", "key": "Twitter", "family": "twitter", "hosts": ["x.com", ...]}, ...]}
"""

import json
import sys
from urllib.parse import urlsplit


def _host(url):
    try:
        host = urlsplit(url).hostname
    except ValueError:
        return None
    return host.lower() if host else None


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
    extractors = []

    try:
        from gallery_dl import extractor

        match = extractor.find(url)
        if match is not None:
            extractors.append({'tool': 'gallery-dl', 'key': match.category, 'family': match.category})
    except ImportError:
        pass

    try:
        seen = set()
        for cls in _yt_dlp_classes():
            if cls.suitable(url) and cls.ie_key() not in seen:
                seen.add(cls.ie_key())
                extractors.append({'tool': 'yt-dlp', 'key': cls.ie_key(), 'family': _yt_dlp_family(cls)})
    except ImportError:
        pass

    return {'extractors': extractors}


def catalog(families):
    families = set(families)
    entries = {}

    def add(tool, key, family, urls):
        entry = entries.setdefault((tool, key), {'tool': tool, 'key': key, 'family': family, 'hosts': set()})
        for url in urls:
            if isinstance(url, str) and (host := _host(url)):
                entry['hosts'].add(host)

    try:
        for cls in _gallery_dl_classes():
            if cls.category in families:
                add('gallery-dl', cls.category, cls.category, (getattr(cls, 'root', None), getattr(cls, 'example', None)))
    except ImportError:
        pass

    try:
        for cls in _yt_dlp_classes():
            family = _yt_dlp_family(cls)
            if family in families:
                add('yt-dlp', cls.ie_key(), family, (t.get('url') for t in cls.get_testcases(include_onlymatching=True)))
    except ImportError:
        pass

    return {'extractors': [dict(e, hosts=sorted(e['hosts'])) for e in entries.values()]}


def main(argv):
    if len(argv) != 3 or argv[1] not in ('resolve', 'catalog'):
        print(__doc__, file=sys.stderr)
        return 2

    if argv[1] == 'resolve':
        output = resolve(argv[2])
    else:
        output = catalog(json.loads(argv[2]))

    json.dump(output, sys.stdout)
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
