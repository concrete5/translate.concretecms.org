# translate.concretecms.com

This repo contains the code for translation at concretecms.org.

## Installation Instructions.

1. Clone this repo.
2. Install dependencies by running `composer install`.
3. Install concrete5, making sure to select the `concrete_cms_translate` starting point. Here is an example of installation via the command line.

`concrete/bin/concrete5 c5:install -vvv --db-server=localhost --db-database=concrete_cms_translate --db-username=user --db-password=password --starting-point=concrete_cms_translate --admin-email=your@email.com --admin-password=password`
