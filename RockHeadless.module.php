<?php namespace ProcessWire;

use TD;

/**
 * @author Bernhard Baumrock, 23.02.2022
 * @license Licensed under MIT
 * @link https://www.baumrock.com
 */
class RockHeadless extends WireData implements Module {

  const prefix = 'rockheadless_';
  const endpoint = 'api';

  private $callbacks;

  public static function getModuleInfo() {
    return [
      'title' => 'RockHeadless',
      'version' => '0.0.4',
      'summary' => 'Provide easy json feeds for using PW as Headless CMS',
      'autoload' => true,
      'singular' => true,
      'icon' => 'bolt',
    ];
  }

  public function init() {
    $this->wire('rockheadless', $this);
    $callbacks = $this->wire(new WireData()); /** @var WireData $callbacks */
    $this->callbacks = $callbacks;

    $this->addHookAfter("ProcessPageEdit::buildFormContent", $this, "addGUI");
    $this->addHookAfter("ProcessPageEdit::processInput", $this, "sleep");
  }

  public function ready() {
    $url = self::endpoint;
    $this->addHookAfter("/$url/{path}", $this, "serve");
  }

  /**
   * Add GUI field to page editor
   * @return void
   */
  public function addGUI(HookEvent $event) {
    $fields = $event->return;
    /** @var Page $page */
    $page = $event->object->getPage();
    if(!$page->numChildren) return;
    $data = $this->wakeup($page);

    /** @var InputfieldCheckbox $check */
    $check = $this->wire(new InputfieldCheckbox());
    $check->name = self::prefix."expose";
    $check->label = ' Expose this page to RockHeadless API';
    $check->attr('checked', $data->expose ? 'checked' : '');
    $check->attr('uk-toggle', ".rh-toggle");

    /** @var InputfieldText $f */
    $f = $this->wire(new InputfieldText());
    $f->name = self::prefix."fields";
    $f->value = implode(", ", $data->fields ?: []);
    $f->addClass('rh-toggle');
    $f->attr('uk-tooltip', 'Fields to expose (comma separated)');
    $f->attr('hidden', !$data->expose);
    $f->attr('placeholder', 'id, title');

    /** @var InputfieldText $selector */
    $selector = $this->wire(new InputfieldText());
    $selector->name = self::prefix."selector";
    $selector->value = $data->selector ?: "parent={page}";
    $selector->addClass('rh-toggle');
    $selector->attr('uk-tooltip', 'Selector used for finding pages');
    $selector->attr('hidden', !$data->expose);

    $url = $this->endpoint($page);
    $fields->add([
      'type' => 'markup',
      'label' => 'RockHeadless',
      'value' => $check->render()
        .$f->render()
        .$selector->render(),
      'notes' => "API-Endpoint: [$url]($url)",
      'collapsed' => !$data->expose,
      'icon' => 'bolt',
    ]);
  }

  /**
   * Get API endpoint for given page
   * @return string
   */
  public function endpoint($page) {
    return $this->wire->pages->get(1)->httpUrl()
      .self::endpoint
      .$page->path;
  }

  /**
   * Show json for given page
   * @return string
   */
  public function ___getData($page) {
    $data = $this->wakeup($page);
    $fields = (array)$data->fields; // prevent wrong ide lints
    $selector = str_replace("{page}", $page, $data->selector);
    $pages = $this->wire->pages->findRaw($selector, $fields);
    $callbacks = $this->callbacks;

    // early exit if no callbacks
    if(!$this->hasHookedField($data)) return array_values($pages);

    // loop page data and execute callbacks
    foreach($pages as $i=>$p) {
      foreach($p as $field=>$val) {
        if(!$cb = $callbacks->$field) continue;
        try {
          $val = $cb->__invoke((object)$p, $page);
        } catch (\Throwable $th) {
          $this->log($th->getMessage());
          $val = '';
        }
        $pages[$i][$field] = $val;
      }
    }
    return array_values($pages);
  }

  /**
   * Is one of the fields hooked?
   * @return bool
   */
  public function hasHookedField($data) {
    foreach($this->callbacks as $field=>$callback) {
      if(in_array($field, $data->fields)) return true;
    }
    return false;
  }

  /**
   * Register a return callback
   */
  public function return($field, $callback) {
    $this->callbacks->set($field, $callback);
  }

  /**
   * Serve JSON
   * @return string
   */
  public function serve(HookEvent $event) {
    $path = $event->path;
    $page = $this->wire->pages->findOne($path);
    if(!$page->id) return;
    $sudo = $this->wire->user->isSuperuser();

    // get api settings
    $api = $this->wakeup($page);
    if(!$api->expose) return;

    $data = $this->getData($page);

    if(!$this->wire->config->ajax AND $sudo) {
      try {
        TD::dumpBig($data);
        return true;
      } catch (\Throwable $th) {
        echo json_encode($data);
      }

    }
    return json_encode($data);
  }

  /**
   * Save data to DB
   * @return void
   */
  public function sleep(HookEvent $event) {
    $form = $event->arguments(0);
    if(!$form instanceof InputfieldForm) return;
    $page = $event->object->getPage();
    $input = $this->wire->input;

    $data = [
      'expose' => $input->post(self::prefix."expose", 'string'),
      'fields' => explode(",", str_replace(" ","", $input->post(self::prefix."fields", 'string'))),
      'selector' => $input->post(self::prefix."selector", 'text'),
    ];

    $page->meta('rockheadless_data', $data);
  }

  /**
   * Get rockheadless data from page metadata
   * @return WireData
   */
  public function wakeup($page) {
    $data = $this->wire(new WireData()); /** @var WireData $data */
    $data->setArray([
      'expose' => false,
      'fields' => [],
      'selector' => '',
    ]);
    $arr = $page->meta('rockheadless_data');
    if(!is_array($arr)) $arr = [];
    $data->setArray($arr);
    return $data;
  }

}
