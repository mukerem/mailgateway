import os
from pathlib import Path
from dotenv import load_dotenv

# Load environment variables from .env file
load_dotenv()

BASE_DIR = Path(__file__).resolve().parent.parent

SECRET_KEY = os.getenv("DJANGO_SECRET_KEY")
DEBUG = False

ALLOWED_HOSTS = [
    "send.nationalidconvertor.com",
    "localhost",
    "127.0.0.1"
]

INSTALLED_APPS = [
    "django.contrib.admin",
    "django.contrib.auth",
    "django.contrib.contenttypes",
    "django.contrib.sessions",
    "django.contrib.messages",
    "django.contrib.staticfiles",
    "emails",
    "microservice",
]

MIDDLEWARE = [
    "django.middleware.security.SecurityMiddleware",
    "django.contrib.sessions.middleware.SessionMiddleware",
    "django.middleware.common.CommonMiddleware",
    "django.middleware.csrf.CsrfViewMiddleware",
    "django.contrib.auth.middleware.AuthenticationMiddleware",
    "django.contrib.messages.middleware.MessageMiddleware",
    "django.middleware.clickjacking.XFrameOptionsMiddleware",
]

ROOT_URLCONF = "config.urls"

TEMPLATES = [
    {
        "BACKEND": "django.template.backends.django.DjangoTemplates",
        "DIRS": [],
        "APP_DIRS": True,
        "OPTIONS": {
            "context_processors": [
                "django.template.context_processors.debug",
                "django.template.context_processors.request",
                "django.contrib.auth.context_processors.auth",
                "django.contrib.messages.context_processors.messages",
            ],
        },
    },
]

WSGI_APPLICATION = "config.wsgi.application"

# --- Email config using cPanel SMTP ---
EMAIL_BACKEND = "django.core.mail.backends.smtp.EmailBackend"
EMAIL_HOST="mail.nationalidconvertor.com"
EMAIL_PORT = 587          # check your cPanel settings (465 if SSL)
EMAIL_USE_TLS = True      # if port 587
EMAIL_USE_SSL = False
EMAIL_HOST_USER = "no-reply@nationalidconvertor.com"   # your mailbox on cPanel
EMAIL_HOST_PASSWORD = os.getenv("EMAIL_HOST_PASSWORD")  # set in .env
DEFAULT_FROM_EMAIL = "no-reply@nationalidconvertor.com"

# Shared secret for the gateway API
EMAIL_API_KEY = os.getenv("EMAIL_API_KEY")  # set in .env on cPanel
TELEBIRR_RECEIPT_URL = "https://transactioninfo.ethiotelecom.et/receipt/{reference}"

# Secure API access
MICROSERVICE_SECRET_KEY = os.getenv("MICROSERVICE_SECRET_KEY")

TIME_ZONE = "Africa/Addis_Ababa"
USE_TZ = True