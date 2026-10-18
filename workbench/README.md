# The example application

This folder is Orchestra Testbench's workbench: the example application the test suite and the documentation use. It is not part of the package.

- `modules/Band`, `modules/Press` and `modules/FanClub`: a Beatles fan-club site, which between them use every feature. Band gives nicknames and changes its lineup; Press prints the headlines on a queue; FanClub welcomes new fans and archives every Band event.
- The cast: John, Paul, George and Ringo, each with an `Instrument`; Pete Best, replaced by Ringo in 1962; and Luciano, a fan and the developer of the site, who may ask but not give a nickname.
- `app/Models/User.php`, `database/`: the users and the migrations the modules need.
- `app/Providers/WorkbenchServiceProvider.php`: the configuration an application would write itself, pointing `laravents.paths` and `laravents.modules.path` at `modules/`.

Nothing here is installed with `composer require laravents/laravents`: the folder is excluded from the Composer dist by `.gitattributes` and autoloaded only under `autoload-dev`. Run it with:

```bash
vendor/bin/testbench serve
```
