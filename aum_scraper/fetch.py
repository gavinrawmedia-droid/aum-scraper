"""Polite HTTP fetching: identifies itself, obeys robots.txt, waits between requests, retries."""
from __future__ import annotations

import time
from urllib.parse import urlsplit

import httpx

from .robots import Robots


class BlockedByRobots(Exception):
    """The dealer's robots.txt does not allow this URL."""


class Fetcher:
    def __init__(self, user_agent: str, delay_seconds: float = 3.0, timeout: float = 30.0,
                 retries: int = 2, transport: httpx.BaseTransport | None = None,
                 sleep=time.sleep):
        self.user_agent = user_agent
        self.delay = delay_seconds
        self.retries = retries
        self.sleep = sleep
        self.client = httpx.Client(
            headers={"User-Agent": user_agent, "Accept-Language": "en-GB,en;q=0.8"},
            timeout=timeout, follow_redirects=True, transport=transport,
        )
        self._robots: dict[str, Robots] = {}
        self._last_hit: dict[str, float] = {}
        self.requests_made = 0

    def _host(self, url: str) -> str:
        p = urlsplit(url)
        return f"{p.scheme}://{p.netloc}"

    def _wait_turn(self, host: str) -> None:
        last = self._last_hit.get(host)
        if last is not None:
            gap = time.monotonic() - last
            if gap < self.delay:
                self.sleep(self.delay - gap)
        self._last_hit[host] = time.monotonic()

    def robots_for(self, url: str) -> Robots:
        host = self._host(url)
        if host not in self._robots:
            text = ""
            try:
                self._wait_turn(host)
                r = self.client.get(host + "/robots.txt")
                self.requests_made += 1
                if r.status_code == 200:
                    text = r.text
            except httpx.HTTPError:
                text = ""  # no robots.txt reachable: treat as allowed, like search engines do
            self._robots[host] = Robots(text, self.user_agent)
        return self._robots[host]

    def is_allowed(self, url: str) -> bool:
        return self.robots_for(url).allowed(url)

    def get(self, url: str) -> httpx.Response:
        if not self.is_allowed(url):
            raise BlockedByRobots(url)
        host = self._host(url)
        attempt = 0
        while True:
            self._wait_turn(host)
            try:
                r = self.client.get(url)
                self.requests_made += 1
            except httpx.HTTPError:
                if attempt >= self.retries:
                    raise
                attempt += 1
                self.sleep(5 * attempt)
                continue
            if r.status_code in (429, 500, 502, 503, 504) and attempt < self.retries:
                attempt += 1
                retry_after = r.headers.get("Retry-After", "")
                self.sleep(int(retry_after) if retry_after.isdigit() else 10 * attempt)
                continue
            r.raise_for_status()
            return r

    def get_json(self, url: str):
        return self.get(url).json()

    def get_text(self, url: str) -> str:
        return self.get(url).text

    def close(self) -> None:
        self.client.close()
