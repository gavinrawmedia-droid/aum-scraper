"""robots.txt checking with Google-style wildcards (* and $).

Python's urllib.robotparser ignores wildcards, and Shopify stores use them
(for example "Disallow: /*?*page=*"), so we match rules ourselves.
"""
from __future__ import annotations

import re
from urllib.parse import urlsplit


def _rule_regex(path: str) -> re.Pattern:
    anchored = path.endswith("$")
    if anchored:
        path = path[:-1]
    pattern = "".join(".*" if ch == "*" else re.escape(ch) for ch in path)
    return re.compile("^" + pattern + ("$" if anchored else ""))


class Robots:
    def __init__(self, text: str, user_agent: str):
        self.rules: list[tuple[bool, str, re.Pattern]] = []
        groups: list[tuple[list[str], list[tuple[bool, str]]]] = []
        agents: list[str] = []
        rules: list[tuple[bool, str]] = []
        last_was_agent = False
        for raw in (text or "").splitlines():
            line = raw.split("#", 1)[0].strip()
            if ":" not in line:
                continue
            key, value = (part.strip() for part in line.split(":", 1))
            key = key.lower()
            if key == "user-agent":
                if not last_was_agent and (agents or rules):
                    groups.append((agents, rules))
                    agents, rules = [], []
                agents.append(value.lower())
                last_was_agent = True
            elif key in ("allow", "disallow"):
                last_was_agent = False
                if value:
                    rules.append((key == "allow", value))
            else:
                last_was_agent = False
        if agents or rules:
            groups.append((agents, rules))

        bot = user_agent.split("/")[0].lower()
        chosen = [r for a, r in groups if any(x != "*" and x in bot for x in a)]
        if not chosen:
            chosen = [r for a, r in groups if "*" in a]
        for group_rules in chosen:
            for allow, path in group_rules:
                self.rules.append((allow, path, _rule_regex(path)))

    def allowed(self, url: str) -> bool:
        parts = urlsplit(url)
        target = (parts.path or "/") + (f"?{parts.query}" if parts.query else "")
        best: tuple[int, bool] | None = None
        for allow, path, regex in self.rules:
            if regex.match(target):
                length = len(path)
                if best is None or length > best[0] or (length == best[0] and allow):
                    best = (length, allow)
        return True if best is None else best[1]
