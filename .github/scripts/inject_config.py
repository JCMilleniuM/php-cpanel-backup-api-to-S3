#!/usr/bin/env python3
##
## inject_config.py — substitute PHP define() constants from environment variables
##
## Called by .github/workflows/deploy.yml during CI.
## Each constant's replacement value is passed as an environment variable.
## This avoids YAML heredoc issues and provides robust regex substitution that
## correctly handles PHP lines with trailing // comments.
##

import os
import re
import sys


def inject_string_const(content: str, name: str, value: str) -> str:
    """
    Replace the quoted value of a PHP define() string constant.

    Matches:  define('<NAME>', '<OLD_VALUE>')
    Replaces: define('<NAME>', '<NEW_VALUE>')

    Trailing PHP comments on the same line are preserved.
    """
    # Match the constant name and capture the surrounding quote chars
    pattern = r"(define\('" + re.escape(name) + r"',\s*')[^']*(')"
    replacement = r"\g<1>" + value.replace("\\", "\\\\") + r"\g<2>"
    result, n = re.subn(pattern, replacement, content)
    if n == 0:
        print(f"WARNING: no match found for string constant '{name}'",
              file=sys.stderr)
    return result


def inject_int_const(content: str, name: str, value: str) -> str:
    """
    Replace the bare-integer value of a PHP define() integer constant.

    Matches:  define('<NAME>', <OLD_INT>)
    Replaces: define('<NAME>', <NEW_INT>)
    """
    pattern = r"(define\('" + re.escape(name) + r"',\s*)\d+(\s*\))"
    replacement = r"\g<1>" + value + r"\g<2>"
    result, n = re.subn(pattern, replacement, content)
    if n == 0:
        print(f"WARNING: no match found for integer constant '{name}'",
              file=sys.stderr)
    return result


def main() -> None:
    """Inject all configuration constants from environment variables."""
    # --- Mapping of PHP constant name → env-var name -------------------------
    # String constants (value is wrapped in single quotes in PHP source)
    string_consts = [
        ("CPANEL_HOST",      "CPANEL_HOST"),
        ("CPANEL_USER",      "CPANEL_USER"),
        ("CPANEL_API_TOKEN", "CPANEL_API_TOKEN"),
        ("S3_ENDPOINT",      "S3_ENDPOINT"),
        ("S3_REGION",        "S3_REGION"),
        ("S3_BUCKET",        "S3_BUCKET"),
        ("S3_ACCESS_KEY",    "S3_ACCESS_KEY"),
        ("S3_SECRET_KEY",    "S3_SECRET_KEY"),
        ("S3_PATH_PREFIX",   "S3_PATH_PREFIX"),
        ("NOTIFY_EMAIL",     "NOTIFY_EMAIL"),
    ]
    # Integer constants (bare numeric value in PHP source, no quotes)
    int_consts = [
        ("CPANEL_PORT", "CPANEL_PORT"),
        ("MAX_BACKUPS",  "MAX_BACKUPS"),
    ]
    # -------------------------------------------------------------------------

    filename = "cpanel_backup_s3.php"

    # Read the PHP source file
    with open(filename, "r", encoding="utf-8") as f:
        content = f.read()

    # Substitute string constants
    for php_name, env_name in string_consts:
        value = os.environ.get(env_name)
        if value is None:
            print(f"ERROR: environment variable '{env_name}' is not set.",
                  file=sys.stderr)
            sys.exit(1)
        content = inject_string_const(content, php_name, value)

    # Substitute integer constants
    for php_name, env_name in int_consts:
        value = os.environ.get(env_name)
        if value is None:
            print(f"ERROR: environment variable '{env_name}' is not set.",
                  file=sys.stderr)
            sys.exit(1)
        content = inject_int_const(content, php_name, value)

    # Write the modified PHP source back
    with open(filename, "w", encoding="utf-8") as f:
        f.write(content)

    print("Configuration constants injected successfully.")


if __name__ == "__main__":
    main()
