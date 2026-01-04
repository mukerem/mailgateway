from __future__ import annotations
import re, time
from dataclasses import dataclass
from datetime import datetime
from decimal import Decimal
from typing import Optional, Tuple, List

import requests
from bs4 import BeautifulSoup
from django.conf import settings
from django.utils import timezone


@dataclass
class TelebirrReceipt:
    reference_number: str
    credited_name: str
    credited_account: str
    status: str
    settled_amount: Decimal
    payment_datetime: datetime


def _fetch_receipt_raw(reference_number: str) -> Optional[TelebirrReceipt]:
    url = settings.TELEBIRR_RECEIPT_URL.format(reference=reference_number)
    max_attempts = 5

    for attempt in range(max_attempts):
        try:
            resp = requests.get(url, timeout=15)
        except:
            return None

        if resp.status_code == 500:
            if attempt < max_attempts - 1:
                time.sleep(1)
                continue
            return None

        if resp.status_code != 200:
            return None

        soup = BeautifulSoup(resp.text, "html.parser")
        text = soup.get_text("\n", strip=True)

        if "This request is not correct" in text:
            return None

        lines = [l.strip() for l in text.splitlines() if l.strip()]

        def after(label):
            for i, line in enumerate(lines):
                if label in line:
                    for j in range(i + 1, len(lines)):
                        if lines[j].strip():
                            return lines[j].strip()
            return None

        credited_name    = after("Credited Party name")
        credited_account = after("Credited party account no")
        status           = after("transaction status")

        payment_date_raw = None
        amount_raw       = None

        for i, line in enumerate(lines):
            if "Settled Amount" in line:
                if i + 2 < len(lines):
                    payment_date_raw = lines[i + 2]
                if i + 3 < len(lines):
                    amount_raw = lines[i + 3]
                break

        if not all([credited_name, credited_account, status, payment_date_raw, amount_raw]):
            return None

        m = re.search(r"([\d.,]+)", amount_raw)
        if not m:
            return None

        amount = Decimal(m.group(1).replace(",", ""))

        try:
            naive_dt = datetime.strptime(payment_date_raw, "%d-%m-%Y %H:%M:%S")
        except:
            return None

        payment_dt = timezone.make_aware(naive_dt)

        return TelebirrReceipt(
            reference_number=reference_number,
            credited_name=credited_name,
            credited_account=credited_account,
            status=status,
            settled_amount=amount,
            payment_datetime=payment_dt,
        )

    return None


def generate_reference_candidates(raw: str, max_variants=512) -> List[str]:
    clean = raw.replace(" ", "").replace("-", "").upper()
    chars = list(clean)

    idx_0o = [i for i, c in enumerate(chars) if c in ("0", "O")]
    idx_1i = [i for i, c in enumerate(chars) if c in ("1", "I")]

    seen, out = set(), []

    def add(x):
        if x not in seen and len(out) < max_variants:
            seen.add(x)
            out.append(x)

    def flip(c):
        return {"0":"O","O":"0","1":"I","I":"1"}.get(c, c)

    # 0/O swaps
    n0 = len(idx_0o)
    for mask in range(1, 1<<n0):
        tmp = chars[:]
        for b in range(n0):
            if mask & (1<<b):
                tmp[idx_0o[b]] = flip(tmp[idx_0o[b]])
        add("".join(tmp))

    # 1/I swaps
    n1 = len(idx_1i)
    for mask in range(1, 1<<n1):
        tmp = chars[:]
        for b in range(n1):
            if mask & (1<<b):
                tmp[idx_1i[b]] = flip(tmp[idx_1i[b]])
        add("".join(tmp))

    # 0/O + 1/I combined
    both = idx_0o + idx_1i
    nb = len(both)
    for mask in range(1, 1<<nb):
        tmp = chars[:]
        for b in range(nb):
            if mask & (1<<b):
                tmp[both[b]] = flip(tmp[both[b]])
        add("".join(tmp))

    return out


def find_receipt_with_variants(raw_reference: str) -> Tuple[Optional[TelebirrReceipt], Optional[str]]:
    rec = _fetch_receipt_raw(raw_reference)
    if rec:
        return rec, raw_reference

    for cand in generate_reference_candidates(raw_reference):
        rec = _fetch_receipt_raw(cand)
        if rec:
            return rec, cand

    return None, None
