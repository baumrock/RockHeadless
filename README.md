# RockHeadless

## Customize return values

```php
// in site/init.php
$rockheadless->return('rockblog_cover', function($page) {
  $images = $page->rockblog_cover;
  if(!is_array($images)) return;
  $file = $images[0]['data'];
  return "site/assets/files/{$page->id}/$file";
});
```

Note that the second parameter of the callback receives the API endpoint:

```php
$rockheadless->return('your_field', function($page, $endpoint) {
  if($endpoint->path == '/your/page') ...
  else ...
});
```
