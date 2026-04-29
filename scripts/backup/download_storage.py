#!/usr/bin/env python3
"""Download all objects from a Supabase Storage bucket recursively."""
import os
import sys
import json
import urllib.request
import urllib.parse
from pathlib import Path

BUCKET = sys.argv[1] if len(sys.argv) > 1 else "business-cards"
PROJECT_URL = os.environ["PROJECT_URL"].rstrip("/")
SERVICE_KEY = os.environ["SERVICE_ROLE_KEY"]
OUT_DIR = Path(sys.argv[2] if len(sys.argv) > 2 else "./storage_backup")
OUT_DIR.mkdir(parents=True, exist_ok=True)

def api_post(path, body):
    req = urllib.request.Request(
        f"{PROJECT_URL}{path}",
        data=json.dumps(body).encode(),
        headers={
            "Authorization": f"Bearer {SERVICE_KEY}",
            "apikey": SERVICE_KEY,
            "Content-Type": "application/json",
        },
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.loads(r.read())

def list_dir(prefix=""):
    """List all entries directly under prefix."""
    return api_post(
        f"/storage/v1/object/list/{BUCKET}",
        {"limit": 1000, "offset": 0, "prefix": prefix, "sortBy": {"column": "name", "order": "asc"}},
    )

def download(remote_path, local_path):
    url = f"{PROJECT_URL}/storage/v1/object/{BUCKET}/{urllib.parse.quote(remote_path)}"
    req = urllib.request.Request(url, headers={
        "Authorization": f"Bearer {SERVICE_KEY}",
        "apikey": SERVICE_KEY,
    })
    local_path.parent.mkdir(parents=True, exist_ok=True)
    with urllib.request.urlopen(req, timeout=60) as r, open(local_path, "wb") as f:
        f.write(r.read())

def walk(prefix=""):
    """Yield all leaf object paths under prefix."""
    entries = list_dir(prefix)
    for entry in entries:
        name = entry["name"]
        full = f"{prefix}{name}" if not prefix else f"{prefix}/{name}"
        if entry.get("id"):
            yield full
        else:
            yield from walk(full)

count = 0
total_bytes = 0
for path in walk(""):
    local = OUT_DIR / path
    try:
        download(path, local)
        size = local.stat().st_size
        total_bytes += size
        count += 1
        if count % 50 == 0:
            print(f"  ... {count} files / {total_bytes/1024/1024:.1f} MB", flush=True)
    except Exception as e:
        print(f"  ERROR {path}: {e}", file=sys.stderr)

print(f"DONE: {count} files / {total_bytes/1024/1024:.1f} MB → {OUT_DIR}")
