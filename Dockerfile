FROM php:7.4-apache

# APENAS isso - nada mais
RUN echo "<?php echo 'Site Online - ' . date('Y-m-d H:i:s'); ?>" > /var/www/html/index.php

EXPOSE 80
CMD ["apache2-foreground"]
