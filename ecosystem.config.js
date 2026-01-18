module.exports = {
  apps: [
    {
      name: "laravel-queue",
      script: "php",
      args: "artisan queue:work --tries=1 --sleep=1 --timeout=90",
      interpreter: "/usr/bin/php",
      instances: 1,
      exec_mode: "fork",
      watch: false,
      autorestart: true,
      max_restarts: 20,
      out_file: "storage/logs/queue-out.log",
      error_file: "storage/logs/queue-error.log",
      env: {
        APP_ENV: "production"
      }
    }
  ]
};

