#!/usr/bin/env python3
"""Dumps the command line options of gallery-dl or yt-dlp as JSON.

Only builds the tools' option parsers: no network access, no extraction. Called by
App\\Service\\Downloader\\Options\\OptionsDumper with an argv array (never a shell).

Usage:
    dump_options.py <gallery-dl|yt-dlp>
        -> {"tool": "yt-dlp", "version": "2026.10.26", "options": [
               {"flags": ["--limit-rate", "-r"], "dest": "ratelimit", "kind": "value", "type": "string",
                "const": null, "metavar": "RATE", "default": null, "help": "...", "group": "Download Options",
                "choices": null, "hidden": false}, ...]}

"kind" is one of:
    value      takes one value (--flag=value)
    append     takes one value and may be repeated
    true       a bare flag that sets its dest to true
    false      a bare flag that sets its dest to false
    const      a bare flag that sets its dest (or appends) a constant: "const" holds its JSON form, if any
    other      anything else (callbacks, custom actions, counters): never configurable
Hidden options (deprecated aliases, internal options) have "hidden": true.
"""

import json
import sys


def _json_value(value):
    try:
        json.dumps(value)
        return value
    except (TypeError, ValueError):
        return None


def _sorted_flags(flags):
    # Long flags first, so the first flag can be used with "--flag=value"
    return sorted(flags, key=lambda flag: (not flag.startswith('--'), flags.index(flag)))


def _yt_dlp():
    import optparse

    from yt_dlp.options import create_parser
    from yt_dlp.version import __version__

    parser = create_parser()
    kinds = {'store_true': 'true', 'store_false': 'false', 'store_const': 'const', 'append': 'append'}
    types = {'int': 'integer', 'float': 'number', 'string': 'string', 'choice': 'string', None: 'string'}

    options = []
    for group in parser.option_groups:
        for option in group.option_list:
            flags = option._long_opts + option._short_opts
            if not flags:
                continue
            kind = 'value' if option.action == 'store' else kinds.get(option.action, 'other')
            help_text = option.help or ''
            default = option.default
            if isinstance(default, tuple) and default and default[0] == 'NO':  # optparse.NO_DEFAULT
                default = None
            options.append({
                'flags': _sorted_flags(flags),
                'dest': option.dest,
                'kind': kind,
                'type': types.get(option.type, 'string') if kind in ('value', 'append') else None,
                'const': _json_value(option.const) if kind == 'const' else None,
                'metavar': option.metavar,
                'default': _json_value(default),
                'help': None if help_text == optparse.SUPPRESS_HELP else help_text.replace('%default', str(default)),
                'group': group.title,
                'choices': list(option.choices) if option.choices else None,
                'hidden': help_text == optparse.SUPPRESS_HELP,
            })

    return __version__, options


def _gallery_dl():
    import argparse

    from gallery_dl.option import ConfigAction, ConfigConstAction, build_parser
    from gallery_dl.version import __version__

    parser = build_parser()
    types = {int: 'integer', float: 'number', str: 'string', None: 'string'}

    options = []
    for group in parser._action_groups:
        for action in group._group_actions:
            if not action.option_strings:
                continue
            if isinstance(action, ConfigConstAction) or type(action) in (argparse._StoreConstAction, argparse._AppendConstAction):
                const = action.const
                if const is True:
                    kind = 'true'
                elif const is False:
                    kind = 'false'
                else:
                    kind = 'const'
            elif isinstance(action, ConfigAction) or type(action) is argparse._StoreAction:
                kind = 'value' if action.nargs in (None, 1) else 'other'
            elif type(action) is argparse._AppendAction:
                kind = 'append'
            elif type(action) is argparse._StoreTrueAction:
                kind = 'true'
            elif type(action) is argparse._StoreFalseAction:
                kind = 'false'
            else:
                kind = 'other'
            default = None if action.default is argparse.SUPPRESS else action.default
            options.append({
                'flags': _sorted_flags(list(action.option_strings)),
                'dest': action.dest,
                'kind': kind,
                'type': types.get(action.type, 'string') if kind in ('value', 'append') else None,
                'const': _json_value(action.const) if kind == 'const' else None,
                'metavar': action.metavar if isinstance(action.metavar, str) else None,
                'default': _json_value(default),
                'help': None if action.help == argparse.SUPPRESS else action.help,
                'group': group.title,
                'choices': [_json_value(c) for c in action.choices] if action.choices else None,
                'hidden': action.help == argparse.SUPPRESS,
            })

    return __version__, options


TOOLS = {'gallery-dl': _gallery_dl, 'yt-dlp': _yt_dlp}


def main(argv):
    if len(argv) != 2 or argv[1] not in TOOLS:
        print('Usage: dump_options.py <%s>' % '|'.join(TOOLS), file=sys.stderr)
        return 2

    version, options = TOOLS[argv[1]]()
    json.dump({'tool': argv[1], 'version': version, 'options': options}, sys.stdout)

    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
