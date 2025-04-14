#!/bin/sh

echo "Waiting for postgres..."
while ! nc -z $SQL_HOST $SQL_PORT; do
    sleep 0.1
done
echo "PostgreSQL started"
python manage.py makemigrations --noinput
python manage.py migrate

# static files
python manage.py collectstatic --no-input

# ⚠️ Cancella tutte le code Celery (attenzione: non reversibile!)
echo "Purging Celery queues..."
celery -A escriptorium purge -f

exec "$@"