
# MCBEProxy

A Minecraft: Bedrock Edition unfinished proxy written just for fun.\
It's similar to the one made by a known user in the protocol community, known as Svile.

## Note

It's unfinished, encryption is half implemented (missing XBL auth to get client key), works only on unecrypted servers as today.








## Screenshots



![Basic](https://github.com/enricoangelon/MCBEProxy/assets/34418030/bbd65eb2-dde8-456a-8d22-700b08c043b3)\
![Connected](https://github.com/enricoangelon/MCBEProxy/assets/34418030/73faf17a-0339-49ae-9fb6-a95e9a74b462)


## Features

- Dedicated chat commands (not visible by other players).
- Fast transfer & dynamic server connection (just a command and you connect to it).
- Code managed to be easy to update and easy to implement mappings.




## Roadmap

- XBL auth

- Full encryption support

- Entity IDs mappings

- Plugin support


## Contributing

Contributions are always welcome!\
Feel free to open PRs and help... :D




## Installation

Please use PocketMine-MP's pre-build PHP binaries: [here](https://github.com/pmmp/PHP-Binaries/releases).\
Composer is also required: [here](https://getcomposer.org/).

To execute inside the project folder:

```bash
  composer install
  php loader.php
```
    
