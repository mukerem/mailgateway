# mailgateway/urls.py
from django.contrib import admin
from django.urls import path
from emails.views import send_email_api

urlpatterns = [
    path("admin/", admin.site.urls),
    path("api/send-email/", send_email_api, name="send-email"),
]
