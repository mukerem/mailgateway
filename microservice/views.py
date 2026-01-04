from django.http import JsonResponse
from django.views.decorators.http import require_GET
from django.conf import settings

from .telebirr import find_receipt_with_variants


@require_GET
def telebirr_proxy(request):
    # Secret Key Validation
    key = request.GET.get("key")
    if not key or key != settings.MICROSERVICE_SECRET_KEY:
        return JsonResponse({
            "ok": False,
            "error": "forbidden",
            "message": "Invalid or missing secret key"
        }, status=403)

    raw_ref = request.GET.get("ref", "").strip()

    if not raw_ref:
        return JsonResponse({
            "ok": False,
            "error": "missing_ref"
        })

    receipt, used = find_receipt_with_variants(raw_ref)

    if receipt is None:
        return JsonResponse({
            "ok": True,
            "exists": False,
            "data": None
        })

    return JsonResponse({
        "ok": True,
        "exists": True,
        "data": {
            "reference_submitted": raw_ref,
            "reference_used": used,
            "credited_name": receipt.credited_name,
            "credited_account": receipt.credited_account,
            "status": receipt.status,
            "settled_amount": str(receipt.settled_amount),
            "payment_datetime_iso": receipt.payment_datetime.isoformat(),
        }
    })
