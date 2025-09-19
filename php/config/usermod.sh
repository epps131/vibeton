#!/usr/bin/env bash

mkdir -p /root/.ssh

cp /var/www/.ssh/* /root/.ssh/

chmod 600 -R /root/.ssh

php-fpm
