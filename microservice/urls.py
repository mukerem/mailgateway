from django.urls import path
from .views import telebirr_proxy

urlpatterns = [
    path("telebirr/", telebirr_proxy),
]