# Rack Planner

Kleine lokale webapp voor 19-inch rackplanning met custom SVG's.

## Functies in deze versie
- SVG uploaden met metadata: naam, HE en breedte
- Rackhoogte instellen in HE
- Items in het rack plaatsen
- Verticaal slepen met snapping op hele HE-posities
- Front en Back view per rack
- Comments per geplaatst item
- SVG-gebaseerde A4 print/PDF basis
- Library CRUD via MySQL/PDO
- Racks en rack-items opslaan/laden via MySQL/PDO

## Lokaal draaien

### Optie 1: in bestaande Apache/Nginx/PHP omgeving
Kopieer de volledige map `rack-planner` naar je localhost webroot, bijvoorbeeld:
- Apache/XAMPP/WAMP: `htdocs/rack-planner` of `www/rack-planner`
- Nginx + PHP-FPM: een virtual host of submap die naar deze map wijst

Open daarna:
- `http://localhost/rack-planner/`

### Optie 2: direct testen met ingebouwde PHP server
Voer uit in een terminal:

```bash
php -S localhost:8080 -t /home/user/output/rack-planner
```

Open daarna:
- `http://localhost:8080`

## Structuur
- `index.php` - UI, editor en export
- `config.php` - lokale databaseconfiguratie
- `db.php` - PDO connectie helper
- `save_item.php` - upload en opslaan van library-items
- `delete_item.php` - soft delete van library-items
- `migrate_library_json.php` - eenmalige import van oude `data/library.json`
- `rack_repository.php` - queries voor templates en racks
- `save_rack.php` - rack metadata + geplaatste items opslaan in MySQL
- `uploads/` - opgeslagen SVG bestanden
- `data/library.json` - oude JSON-bron, alleen nog voor optionele migratie

## Databaseconfiguratie

De app gebruikt MySQL via PDO voor zowel de library als opgeslagen racks.

1. Pas `config.php` aan met je lokale databasegegevens, of zet deze environment variables:
   - `RACK_PLANNER_DB_HOST`
   - `RACK_PLANNER_DB_PORT`
   - `RACK_PLANNER_DB_NAME`
   - `RACK_PLANNER_DB_USER`
   - `RACK_PLANNER_DB_PASS`
2. Zorg dat het schema is geïmporteerd.
3. Uploads blijven op schijf staan in `uploads/`.
4. Klik in de app op `Rack opslaan` om metadata, front/back-items en comments naar MySQL te schrijven.
5. Bestaande racks verschijnen links onder `Opgeslagen racks`.

## Migratie van bestaande library.json

Als je al items in `data/library.json` hebt staan, voer dan eenmalig uit:

```bash
php /pad/naar/rack-planner/migrate_library_json.php
```

Daarna draait de library op de database.

## Volgende logische stappen
- Templatebeheerpagina bouwen
- Coolblue template metadata live koppelen aan export
- Export volledig laten draaien op database-data zonder lokale draft-afhankelijkheid

- `saved_racks.php` - aparte beheerpagina voor opgeslagen racks
- `rack_actions.php` - openen/dupliceren/verwijderen acties voor opgeslagen racks

- `saved_templates.php` - aparte beheerpagina voor templates
- `template_editor.php` - template-instellingen, logo-upload en veldconfiguratie
- `save_template.php` - template opslaan naar MySQL
- `template_actions.php` - dupliceren/verwijderen acties voor templates

## Canonical Rack Planner location

Rack Planner is now maintained under `tools/rack-planner/`.
Legacy root Rack Planner pages/endpoints are thin compatibility wrappers only.
