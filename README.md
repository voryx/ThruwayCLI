Thruway CLI
===========

Thruway CLI is a command line tool that allows you to execute WAMP commands for testing purposes.


## Installation

Download zip file or clone this repository and then install dependencies using [composer](https://getcomposer.org).

```bash
$ composer.phar install
```

## Example

```bash
bin/thruway wss://demo.crossbar.io/ws realm1
```

## Commands

```bash
  exit [<code>] 
  help  
  publish <uri> <value> [<options>]     
  call <uri> [<args>] [<options>]       
  subscribe <uri> [<options>]       
  cancel        
  register <uri> [<options>]   
```
  
 
### Subscribe
```bash
thruway> subscribe demo.topic
thruway> subscribe demo. '{ "match": "prefix" }'
```    
  
### Publish
```bash
thruway> publish demo.topic 'Hello World'
thruway> publish demo.topic 'Hello World' '{"exclude_me":false}'

```  

### Call
```bash
thruway> call demo.rpc 123
thruway> call demo.rpc "Hello World"
thruway> call demo.rpc '["Hello", "World"]'
thruway> call demo.rpc '["Hello", "World"]' '{"receive_progress": true}'
```


### Register
```bash
thruway> register demo.rpc
thruway> register demo.rpc '{"progress": true}'
```