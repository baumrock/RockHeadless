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

  public static function getModuleInfo() {
    return [
      'title' => 'RockHeadless',
      'version' => '0.0.3',
      'summary' => 'Provide easy json feeds for using PW as Headless CMS',
      'autoload' => true,
      'singular' => true,
      'icon' => 'bolt',
    ];
  }

  public function init() {
    $url = self::endpoint;
    $this->addHookAfter("/$url/{path}", $this, "serve");
    $this->addHookAfter("ProcessPageEdit::buildFormContent", $this, "addGUI");
    $this->addHookAfter("ProcessPageEdit::processInput", $this, "sleep");
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
    $selector = str_replace("{page}", $page, $data->selector);
    $pages = $this->wire->pages->findRaw($selector, $data->fields);
    return array_values($pages);
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

    $page->meta('rockheadless', $data);
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
    $data->setArray($page->meta('rockheadless') ?: []);
    return $data;
  }

}
