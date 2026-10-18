-- Підміна прод-хостів на стендові (виконується після дампа сайту при першому підйомі тому MySQL — db-init/ сортується за іменем).
-- __SITE_HOST__ → апекс-хост стенда з реєстру (domains.site, напр. example.stand.myserver.test):
--   sed "s/__SITE_HOST__/example.stand.myserver.test/g" docker/traefik/90-stand-hosts.sql.tpl > <root>/db-init/90-stand-hosts.sql
-- Інші міста = <slug>-__SITE_HOST__ (wildcard-серт traefik зазвичай лише одного рівня; так само чекає HostRegexp у compose).
UPDATE service_site SET apex_domain = '__SITE_HOST__';
UPDATE service_city SET host = '__SITE_HOST__' WHERE is_apex = 1;
UPDATE service_city SET host = CONCAT(slug, '-__SITE_HOST__') WHERE is_apex = 0;
