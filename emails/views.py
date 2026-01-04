from django.shortcuts import render

# Create your views here.
# emails/views.py
import json
from django.conf import settings
from django.core.mail import EmailMultiAlternatives
from django.http import JsonResponse
from django.views.decorators.csrf import csrf_exempt


@csrf_exempt
def send_email_api(request):
    # Only allow POST
    if request.method != "POST":
        return JsonResponse({"detail": "Method not allowed"}, status=405)

    # Simple auth using a shared API key in header
    api_key = request.headers.get("X-EMAIL-API-KEY")
    if not api_key or api_key != settings.EMAIL_API_KEY:
        return JsonResponse({"detail": "Unauthorized"}, status=401)

    # Parse JSON body
    try:
        payload = json.loads(request.body.decode("utf-8"))
    except json.JSONDecodeError:
        return JsonResponse({"detail": "Invalid JSON"}, status=400)

    to = payload.get("to")
    subject = payload.get("subject")
    html = payload.get("html")
    text = payload.get("text") or ""

    # Basic validation
    if not to or not subject or not html:
        return JsonResponse(
            {"detail": "Fields 'to', 'subject', 'html' are required"},
            status=400,
        )

    # Support string or list for "to"
    if isinstance(to, str):
        to_list = [to]
    else:
        to_list = list(to)

    msg = EmailMultiAlternatives(
        subject=subject,
        body=text or " ",  # plain text fallback
        from_email=settings.DEFAULT_FROM_EMAIL,
        to=to_list,
    )
    msg.attach_alternative(html, "text/html")

    try:
        msg.send()
    except Exception as e:
        return JsonResponse({"detail": f"Send failed: {e}"}, status=500)

    return JsonResponse({"detail": "ok"}, status=200)
