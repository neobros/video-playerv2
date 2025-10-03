php artisan queue:work --tries=1
sudo -u www-data php artisan queue:work --tries=1 > storage/logs/queue.log 2>&1 &
pkill -f "php artisan queue:work"