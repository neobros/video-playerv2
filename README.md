php artisan queue:work --tries=1
sudo -u www-data php artisan queue:work --tries=1 > storage/logs/queue.log 2>&1 &
pkill -f "php artisan queue:work"



pm2 create
cd /var/www/video-playerv2 
pm2 delete laravel-queue
pm2 start artisan \
  --name="laravel-queue" \
  --interpreter="/usr/bin/php8.3" \
  -- queue:work --tries=1 --sleep=1 --timeout=90


pm2 restart 
pm2 restart 2




ls -l /etc/nginx/sites-enabled/

sudo nano /etc/nginx/sites-available/player.vampior.com

sudo nginx -t
sudo systemctl reload nginx

sudo rm -f /etc/nginx/sites-enabled/player.vampior.com
sudo rm -f /etc/nginx/sites-available/player.vampior.com
sudo nano /etc/nginx/sites-available/player.vampior.com
sudo ln -s /etc/nginx/sites-available/player.vampior.com /etc/nginx/sites-enabled/player.vampior.com