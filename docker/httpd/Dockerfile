# alpine is more secure
FROM nginx:stable-alpine

ENV NGINX_APPLICATION_HOSTNAME application

RUN mkdir -p /etc/nginx/ssl

COPY docker/httpd/vhost.conf /etc/nginx/templates/default.conf.template
COPY api/public/ /fleetbase/api/public/