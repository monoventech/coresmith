import os
from pathlib import Path

BASE_DIR = Path(__file__).resolve().parent.parent
SECRET_KEY = os.environ["SECRET_KEY"]
DEBUG = os.environ.get("DEBUG") == "1"
ALLOWED_HOSTS = os.environ.get("ALLOWED_HOSTS", "localhost").split(",")
INSTALLED_APPS = ["django.contrib.admin", "django.contrib.auth", "django.contrib.contenttypes", "django.contrib.sessions", "django.contrib.messages", "django.contrib.staticfiles"]
ROOT_URLCONF = "shop.urls"
WSGI_APPLICATION = "shop.wsgi.application"
STATIC_URL = "static/"
STATIC_ROOT = BASE_DIR / "staticfiles"
CELERY_BROKER_URL = os.environ.get("CELERY_BROKER_URL", "redis://127.0.0.1:6379/0")
