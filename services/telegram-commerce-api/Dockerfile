FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1
WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY app ./app
RUN useradd --create-home --uid 10001 commerce && mkdir -p /app/data && chown -R commerce:commerce /app
USER commerce
EXPOSE 8088
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8088", "--proxy-headers"]

