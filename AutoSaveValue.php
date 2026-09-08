<?php
/**
 * REDCap External Module: Auto-Save Value 
 * Action tags to trigger automatic saving of values during data entry.
 * @author Luke Stevens, Murdoch Children's Research Institute
 *
 * Offline mode, added below: an encrypted copy of the form is kept on the device
 * and offered back after a reload, and on data entry forms changed values are
 * saved in the background. It is switched on per instrument in the project
 * settings rather than per field, and mirrors whatever the instrument holds.
 *
 * The background writes go through REDCap::saveData(), which is an API level
 * write and enforces none of the protections the data entry screen gives for
 * free, so checkCaller() re-checks record locks, e-signatures, form-level
 * rights and data access groups on every request.
 * @author Syed Gilani, The Kids Research Institute Australia
 */

namespace MCRI\AutoSaveValue;

use ExternalModules\AbstractExternalModule;

class AutoSaveValue extends AbstractExternalModule
{
    protected const AUTOSAVE_ACTION = 'save';
    protected const TAG_AUTOSAVE = '@AUTOSAVE';
    protected const TAG_AUTOSAVE_FORM = '@AUTOSAVE-FORM';
    protected const TAG_AUTOSAVE_SURVEY = '@AUTOSAVE-SURVEY';
    protected const TAG_AUTOSAVE_FORM_HIDEICON = '@AUTOSAVE-FORM-HIDEICON';
    protected const TAG_AUTOSAVE_SURVEY_SHOWICON = '@AUTOSAVE-SURVEY-SHOWICON';
    protected $noauth;
    protected $project_id;
    protected $record;
    protected $event_id;
    protected $instrument;
    protected $instance;
    protected $jsObjName;
    protected $fieldsSaveOnLoad;
    protected $fieldsSaveOnEdit;
    protected $autoSaveFields;
    protected $autoSaveIconSwapFields;
    protected $autoSaveOnLoadFields;
    protected $defaultNoAutoSaveFields;
    static $SupportedFieldTypes = [
        // 'calc',
        // 'checkbox',
        // 'file', // includes signature
        'radio',
        'select', // includes auto-complete
        // 'slider',
        'sql',
        'text', // excludes ontology
        'textarea',
        'truefalse',
        'yesno'
    ];

    /* ---------------------------------------------------------------- */
    /* offline mode                                                     */
    /* ---------------------------------------------------------------- */

    protected const SYNC_ACTION = 'sync';
    protected const DEFAULT_FLUSH_SECONDS = 10;
    protected const MIN_FLUSH_SECONDS = 3;
    protected const DEFAULT_TTL_HOURS = 12;
    protected const MAX_FIELDS_PER_BATCH = 200;

    /**
     * Field types offline mode will write back. Wider than the action-tag
     * layer's list, because the queue can carry a checkbox group as a set where
     * a single-field save cannot. The ones left out are left out for a reason:
     *  - calc and @CALCTEXT recalculate on the server, so writing them is
     *    pointless and would fight Data Quality rule H
     *  - file and signature cannot be sensibly held in a queue
     *  - slider and rich text are not mirrored, and the restore bar says so
     */
    static $SyncableFieldTypes = [
        'checkbox',
        'radio',
        'select',
        'sql',
        'text',
        'textarea',
        'truefalse',
        'yesno'
    ];


    public function redcap_data_entry_form(int $project_id, ?string $record, string $instrument, int $event_id, ?int $group_id, int $repeat_instance=1) {
        if (isset($_GET['em_preview_instrument']) && $_GET['em_preview_instrument']=='1') return; // don't save if previewing from designer using Preview Instrument EM
        global $Proj, $draft_preview_enabled;
        if ($draft_preview_enabled) return; // no auto-save in draft mode preview

        // The action tags cannot do anything until the record exists, because there
        // is nothing to save a value to. Offline mode still runs in that case: it
        // mirrors the form to the device even when the queue is shut, which is the
        // whole point on a new record.
        if (!is_null($record)) {
            $this->noauth = false;
            $this->project_id = $project_id;
            $this->record = $record;
            $this->event_id = $event_id;
            $this->instrument = $instrument;
            $this->instance = $repeat_instance;
            $pf=(isset($Proj->forms[$this->instrument]['fields'])) ? array_keys($Proj->forms[$this->instrument]['fields']) : array();
            $this->includeSaveFunctions($pf);
        }

        $this->includeOfflineLayer($project_id, $record, $instrument, $event_id, $repeat_instance);
    }

    public function redcap_survey_page(int $project_id, ?string $record, string $instrument, int $event_id, ?int $group_id, string $survey_hash, ?string $response_id, int $repeat_instance = 1) {
        global $pageFields;

        // As above: the action tags need the record to exist, offline mode does
        // not. On a survey offline mode only mirrors the page to the device and
        // offers it back after a reload, which needs no record and no login.
        if (!is_null($record)) {
            $this->noauth = true;
            $this->project_id = $project_id;
            $this->record = $record;
            $this->event_id = $event_id;
            $this->instance = $repeat_instance;
            $pf=(isset($_GET['__page__'])) ? $pageFields[$this->escape($_GET['__page__'])] : array();
            $this->includeSaveFunctions($pf);
        }

        // offline mode: this page's fields, read independently of the above
        $page = (isset($_GET['__page__']) && is_numeric($_GET['__page__']) && (int) $_GET['__page__'] > 0) ? (int) $_GET['__page__'] : 1;
        $onPage = (is_array($pageFields) && isset($pageFields[$page]) && is_array($pageFields[$page])) ? $pageFields[$page] : null;
        if (is_array($onPage) && !count($onPage)) return; // the e-consent certification page: no fields to hold

        $this->includeOfflineLayer($project_id, $record, $instrument, $event_id, $repeat_instance, [
            'survey'     => true,
            'hash'       => (string) $survey_hash,
            'page'       => $page,
            'pageFields' => $onPage
        ]);
    }
    /**
     * The survey is finished, so nothing this device holds for it is unsaved any
     * more. Remove every row for this survey response, or the last page's
     * answers would be offered to whoever opens that survey next on a shared
     * tablet. Only the identifiers are needed here, so the module's JavaScript
     * is not loaded; the few lines below do the one job.
     */
    public function redcap_survey_acknowledgement_page($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance = 1) {
        if (!$this->instrumentIsProtected($instrument)) return;
        $this->instrument = $instrument;
        $this->aimAt($event_id, $repeat_instance);
        $series = $this->surveySeries($project_id, $instrument);
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $seriesJson = json_encode($series, $flags);
        $recordJson = json_encode(is_null($record) ? null : (string) $record, $flags);
        if ($seriesJson === false || $recordJson === false) return;
        ?>
        <script type="text/javascript">
        (function() {
            var series = <?=$seriesJson?>, record = <?=$recordJson?>, tab = null;
            try { tab = sessionStorage.getItem('asvo:tab'); } catch (e) {}
            try {
                var open = indexedDB.open('autoSaveValueOffline', 1);
                // never create the database here: an empty one would break the
                // module on this browser for good
                open.onupgradeneeded = function() { try { open.transaction.abort(); } catch (e) {} };
                open.onsuccess = function() {
                    var db = open.result;
                    if (!db.objectStoreNames.contains('drafts')) { db.close(); return; }
                    var cursor = db.transaction('drafts', 'readwrite').objectStore('drafts').openCursor();
                    cursor.onsuccess = function() {
                        var c = cursor.result;
                        if (!c) { db.close(); return; }
                        var row = c.value;
                        // only this respondent's rows: same record, or written by this tab
                        if (row && row.series === series && ((record !== null && row.record === record) || (tab && row.tab === tab))) c.delete();
                        c.continue();
                    };
                };
            } catch (e) {}
        })();
        </script>
        <?php
    }

    /**
     * "Save & Exit Form" on a brand new record lands on the record home page,
     * where no form hook runs. The 'new-record' row that tab wrote is spent the
     * moment the record exists, and if it were left, the next new record this
     * tab creates would be offered the previous one's answers. So on the record
     * home page of a record that exists, this tab's new-record rows are removed.
     * Other tabs' rows are left: a tab that died before saving is the case worth
     * keeping.
     */
    public function redcap_every_page_top($project_id) {
        if (!defined('PAGE') || PAGE !== 'DataEntry/record_home.php') return;
        if (empty($_GET['id']) || isset($_GET['auto'])) return;   // ?auto=1 is the not-yet-created record
        if (!defined('USERID') || USERID === '') return;
        if (!class_exists('\Records') || !method_exists('\Records', 'recordExists')) return;
        try {
            if (!\Records::recordExists($project_id, $_GET['id'])) return;
        } catch (\Throwable $th) {
            return;
        }
        $series = implode('|', ['entry', (int) $project_id, USERID]);
        $json = json_encode($series, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if ($json === false) return;
        ?>
        <script type="text/javascript">
        (function() {
            var series = <?=$json?>, tab = null;
            try { tab = sessionStorage.getItem('asvo:tab'); } catch (e) {}
            if (!tab) return;
            try {
                var open = indexedDB.open('autoSaveValueOffline', 1);
                open.onupgradeneeded = function() { try { open.transaction.abort(); } catch (e) {} };
                open.onsuccess = function() {
                    var db = open.result;
                    if (!db.objectStoreNames.contains('drafts')) { db.close(); return; }
                    var cursor = db.transaction('drafts', 'readwrite').objectStore('drafts').openCursor();
                    cursor.onsuccess = function() {
                        var c = cursor.result;
                        if (!c) { db.close(); return; }
                        var row = c.value;
                        if (row && row.series === series && row.tab === tab && (row.record === null || row.record === '')) c.delete();
                        c.continue();
                    };
                };
            } catch (e) {}
        })();
        </script>
        <?php
    }

    /**
     * One survey response's pages share this, whatever page, record or link hash
     * they carry. The hash is deliberately left out: a public survey switches
     * from the public link to the record's own link after its first page, so a
     * series keyed by hash would split the response in two and page one's row
     * would never be retired.
     */
    protected function surveySeries($project_id, $instrument) {
        return implode('|', ['survey', (int) $project_id, (int) $this->event_id, $instrument, (int) $this->instance]);
    }

    protected function filterPageFieldsByTag($pageFields, $tag) {
        global $Proj;
        $taggedFields = array();
        foreach ($pageFields as $f) {
            if (!in_array($Proj->metadata[$f]['element_type'], static::$SupportedFieldTypes)) continue;

            $rawAnnotation = $Proj->metadata[$f]['misc'];
            $annotation = \Form::replaceIfActionTag($rawAnnotation, $Proj->project_id, $this->record, $this->event_id, $this->instrument, $this->instance);
            if (preg_match("/(^|\s)$tag($|\s|=)/",$annotation)) {

                if (!($Proj->metadata[$f]['element_type']=='text' && (preg_match("/@CALCTEXT|@CALCDATE/",$annotation)))) { 
                    $taggedFields[] = $f;
                }
            }
        }
        return $taggedFields;
    }

    protected function includeSaveFunctions($pageFields) {
        if (empty($pageFields)) return;
        $this->autoSaveFields = $this->filterPageFieldsByTag($pageFields, static::TAG_AUTOSAVE);
        if ($this->noauth) {
            $this->autoSaveIconSwapFields = $this->filterPageFieldsByTag($pageFields, static::TAG_AUTOSAVE_SURVEY_SHOWICON);
            $this->autoSaveFields = array_merge($this->autoSaveFields, $this->autoSaveIconSwapFields, $this->filterPageFieldsByTag($pageFields, static::TAG_AUTOSAVE_SURVEY));
        } else {
            $this->autoSaveIconSwapFields = $this->filterPageFieldsByTag($pageFields, static::TAG_AUTOSAVE_FORM_HIDEICON);
            $this->autoSaveFields = array_merge($this->autoSaveFields, $this->autoSaveIconSwapFields, $this->filterPageFieldsByTag($pageFields, static::TAG_AUTOSAVE_FORM));
        }
        if (count($this->autoSaveFields) === 0 ) return;
        $this->autoSaveFields = array_unique($this->autoSaveFields); // get rid of any duplicates e.g. if have both  @AUTOSAVE-SURVEY and @AUTOSAVE-SURVEY-SHOWICON
        
        $fieldsDefaultOrSetvalue = array();
        $pageHasData = \Records::fieldsHaveData($this->record, $pageFields, $this->event_id, $this->instance);
        if (!$pageHasData) {
            foreach (array('@DEFAULT','@TODAY','@TODAY-SERVER','@TODAY-UTC','@NOW','@NOW-SERVER','@NOW-UTC') as $tag) {
                $fieldsDefaultOrSetvalue = array_merge($fieldsDefaultOrSetvalue, $this->filterPageFieldsByTag($pageFields, $tag));
            }
        }
        $fieldsDefaultOrSetvalue = array_unique(array_merge($fieldsDefaultOrSetvalue, $this->filterPageFieldsByTag($pageFields, '@SETVALUE')));
        $this->autoSaveOnLoadFields = array_values(array_intersect($this->autoSaveFields, $fieldsDefaultOrSetvalue));
        $this->defaultNoAutoSaveFields = array_values(array_diff($fieldsDefaultOrSetvalue, $this->autoSaveFields));

        // write default reason text to document rather than into JS so as to give opportunity to modify text outside of this module
        $default_reason = $this->getProjectSetting('default-reason-for-change');
        $default_reason = (empty($default_reason)) ? $this->PREFIX : $default_reason;
        echo '<span id="AutoSaveReason" class="d-none">'.$this->escape(\REDCap::filterHtml($default_reason)).'</span>';

        $this->initializeJavascriptModuleObject();
        $this->jsObjName = $this->getJavascriptModuleObjectName();
        ?>
        <!-- Auto-Save Value external module: start-->
        <style type="text/css">
            @keyframes pulse {
                0% { transform: scale(1); }
                50% { transform: scale(0.8); }
                100% { transform: scale(1); }
            }
            .asv-default { color: #888; }
            .asv-save { color: green; display: none; }
            .asv-fail { color: red; display: none; }
            .pulse { animation: pulse 1s infinite; }
        </style>
        <script type="text/javascript">
            $(function(){
                var module = <?=$this->jsObjName?>;
                module.isSurvey = <?=($this->noauth)?1:0;?>;
                module.autoSaveFields = JSON.parse('<?=json_encode($this->autoSaveFields)?>');
                module.iconSwapFields = JSON.parse('<?=json_encode($this->autoSaveIconSwapFields)?>');
                module.autoSaveOnLoadFields = JSON.parse('<?=json_encode($this->autoSaveOnLoadFields)?>');
                module.defaultNoAutoSaveFields = JSON.parse('<?=json_encode($this->defaultNoAutoSaveFields)?>');
                module.singleFieldChange = false;
                module.iconSpan = '<span class="asv-icons"><i class="fas fa-save mx-1 asv-default" title="Auto-save field value on change"></i><i class="fas fa-save mx-1 asv-save" title="Saved"></i><i class="fas fa-times mx-1 asv-fail" title="Save failed"></i></span>'

                module.findInput = function(field) {
                    return $('[name='+field+']:first');
                };

                module.findGroupInputs = function(field) {
                    let input = $('[name='+field+']:first');
                    let type = module.getFieldType(field)
                    if (type=='radio') {
                        return $('[name='+field+'___radio]'); // group of input type="radio", one per value
                    } else {
                        return $(input);
                    }
                };

                module.getFieldType = function(field) {
                    let f = module.findInput(field); type = '';
                    let elemTag = $(f).eq(0).prop('nodeName');
                    let elemType = $(f).eq(0).prop('type')
                    if (elemTag=='INPUT' && $(f).eq(0).hasClass('hiddenradio')) {
                        type = 'radio'; // covers yesno and truefalse too
                    } else if (elemTag=='INPUT' && $(f).eq(0).hasClass('autosug-ont-field')) {
                        type = 'text-ontology';
                    } else if (elemTag=='INPUT' && elemType=='text') {
                        type = 'text';
                    } else if (elemTag=='SELECT' && $(f).eq(0).hasClass('rc-autocomplete')) {
                        type = 'dropdown-autocomplete';
                    } else if (elemTag=='SELECT') {
                        type = 'dropdown';
                    } else if (elemTag=='TEXTAREA') {
                        type = 'notes';
                    }
                    return type;
                };

                module.appendIcons = function(field) {
                    let type = module.getFieldType(field);
                    let span = module.iconSpan;
                    if (module.isSurvey && !module.iconSwapFields.includes(field) // hide icons on survey unless directed to show
                            || !module.isSurvey && module.iconSwapFields.includes(field) ) { // hide icons on form when directed to hide
                        span = span.replace('class="asv-icons"', 'class="asv-icons d-none"');
                    }
                    if (type=='dropdown-autocomplete') {
                        module.findInput(field).closest('span[data-kind=field-value]').find('div').append(span);
                    } else if (type=='radio') {
                        let radioButtons = module.findGroupInputs(field);
                        if ($(radioButtons).eq(0).parent('td.choicematrix').length) {
                            $(radioButtons).eq(0).closest('table[role=presentation]').siblings('.resetLinkParent:first').prepend(span);
                        } else {
                            $(radioButtons).eq(0).closest('span[data-kind=field-value]').siblings('.resetLinkParent:first').prepend(span);
                        }
                    } else if (type=='notes') {
                        module.findInput(field).closest('span[data-kind=field-value]').siblings('.expandLinkParent:first').prepend(span);
                    } else if (type=='text-ontology') {
                        // is working but suppressed: module.findInput(field).after(span);
                    } else {
                        module.findInput(field).after(span);
                    }
                }

                module.getFieldIcon = function(field, icon) {
                    let type = module.getFieldType(field);
                    if (type=='radio') {
                        return module.findInput(field).closest('[data-kind=field-value]').siblings('.resetLinkParent:first').find('i.asv-'+icon);
                    } else if (type=='notes') {
                        return module.findInput(field).closest('[data-kind=field-value]').siblings('.expandLinkParent:first').find('i.asv-'+icon);
                    } else {
                        return module.findInput(field).closest('[data-kind=field-value]').find('i.asv-'+icon);
                    }
                };

                module.addUpdateHander = function(field) {
                    let field_input = module.findInput(field);
                    let field_type = module.getFieldType(field);
                    if (field_type=='radio') {
                        let radioButtons = module.findGroupInputs(field);
                        radioButtons.on('change', {field:field}, module.updateHandler); // field_input is group of inputs, one per choice
                    } else if (field_type=='dropdown-autocomplete') {
                        field_input.on('change', {field:field}, module.updateHandler);
                    } else if (field_type=='text-ontology') {
                        // not working: field_input.on('autocompletechange', {field:field}, module.updateHandler);
                    } else {
                        field_input.on('blur', {field:field}, module.updateHandler);
                    }
                };

                module.updateHandler = function(e) {
                    module.save(e.data.field, module.readFieldValue(e.data.field, this));
                };

                module.saveSuccess = function(field) {
                    module.getFieldIcon(field,'save').fadeIn(1000);
                    module.findInput(field).removeClass('calcChanged');
                    if (module.singleFieldChange) dataEntryFormValuesChanged = false; // only this autosave field has changed - will reset dataEntryFormValuesChanged to false after save
                };
                module.saveFailed = function(field) {
                    module.getFieldIcon(field,'fail').fadeIn(1000);
                };
                
                module.save = function(field, value){
                    module.getFieldIcon(field,'default').addClass('pulse').show();
                    module.getFieldIcon(field,'save').hide();
                    module.getFieldIcon(field,'fail').hide();
                    module.singleFieldChange = !dataEntryFormValuesChanged;
                    module.ajax('<?=static::AUTOSAVE_ACTION?>', [field, value, $('#AutoSaveReason').text()]).then(function(response) {
                        module.getFieldIcon(field,'default').removeClass('pulse').hide();
                        if (response) {
                            module.saveSuccess(field);
                        } else {
                            module.saveFailed(field);
                        }
                    }).catch(function(err) {
                        console.log('Auto-save failed: field=\''+field+'\'; value=\''+value+'\': '+err);
                        module.saveFailed(field);
                    });
                };

                module.readFieldValue = function(field, triggerElement=null) {
                    if (triggerElement==null) {
                        triggerElement = module.findInput(field);
                    }
                    return $(triggerElement).eq(0).val();
                };

                module.init = function() {
                    if ($('#autosave-default-comment')) {
                        module.defaultComment = $('#autosave-default-comment').text();
                    }
                    module.autoSaveFields.forEach((asf) => { 
                        module.appendIcons(asf);
                        module.addUpdateHander(asf);
                    });
                    if (dataEntryFormValuesChanged && module.autoSaveOnLoadFields.length) {
                        // save fields with values from @DEFAULT, @TODAY, @NOW, or @SETVALUE (including empty)
                        module.autoSaveOnLoadFields.forEach(function(asf) {
                            module.save(asf, module.readFieldValue(asf));
                        });
                        if (module.defaultNoAutoSaveFields.length===0) {
                            // all auto-populated fields have been saved, no need for save prompt on leaving
                            dataEntryFormValuesChanged = false;
                        }
                    }
                };

                module.init();
            });
        </script>
        <!-- Auto-Save Value external module: end-->
        <?php
    }

    /**
     * Offline mode's half of the data entry hook. Separate from
     * includeSaveFunctions() because the two layers share nothing but the page:
     * this one is chosen per instrument in the settings, that one per field by
     * action tag, and a field may quite reasonably be covered by both.
     */
    /**
     * May this field be written through the action-tag endpoint? It must exist on
     * the instrument, have a metadata row (which excludes the _complete field),
     * be a type the original module supports (which excludes calc), and carry an
     * @AUTOSAVE tag appropriate to the mode. Survey mode is deliberately stricter
     * because that endpoint is reachable without logging in.
     */
    protected function fieldMayAutoSave($field, $instrument) {
        global $Proj;
        if ($field === '') return false;
        if (!isset($Proj->forms[$instrument]['fields'][$field])) return false;
        if (!isset($Proj->metadata[$field])) return false;

        $meta = $Proj->metadata[$field];
        if (!in_array($meta['element_type'], static::$SupportedFieldTypes)) return false;
        if ($this->hasCalculatedActionTag($meta)) return false;

        $tags = $this->noauth
            ? [static::TAG_AUTOSAVE, static::TAG_AUTOSAVE_SURVEY, static::TAG_AUTOSAVE_SURVEY_SHOWICON]
            : [static::TAG_AUTOSAVE, static::TAG_AUTOSAVE_FORM, static::TAG_AUTOSAVE_FORM_HIDEICON];

        foreach ($tags as $tag) {
            if ($this->hasActionTag($meta, $tag)) return true;
        }
        return false;
    }

    protected function includeOfflineLayer($project_id, $record, $instrument, $event_id, $repeat_instance, $survey = null) {
        if (!$this->instrumentIsProtected($instrument)) return;

        $this->project_id = $project_id;
        $this->record = $record;
        $this->instrument = $instrument;
        $this->aimAt($event_id, $repeat_instance);
        $event_id = $this->event_id;
        $onSurvey = is_array($survey) && !empty($survey['survey']);

        // No point offering to sync if the user could not save this form by hand.
        // Wrapped because checkCaller talks to the database, and a schema surprise
        // must degrade to "no background saving" rather than take the data entry
        // screen down with it.
        // The reason a page is mirror-only is handed to the browser as well, so
        // "why is the pill stuck on Held on this device" has a one-line answer in
        // the console instead of a guess. It is not written to the project log:
        // a read-only user opening a form is not an event worth recording.
        $mayWrite = false;
        $reason = '';
        try {
            if ($onSurvey) {
                // A respondent is not a logged-in user, so none of the checks the
                // endpoint relies on mean anything here. The page is mirrored to
                // the device and offered back after a reload; nothing is sent.
                $reason = 'This is a survey page. Answers are held on this device and offered back if the page reloads; nothing is sent in the background, because a survey respondent is not a logged-in user.';
            } else if (is_null($record)) {
                $reason = 'This record has not been created yet, so there is nothing on the server to save to. Background saving starts once the form is saved for the first time.';
            } else {
                $this->refusalDetail = '';
                $verdict = $this->checkCaller($project_id, $record, $instrument, $event_id, $this->instance);
                if ($verdict === true) $mayWrite = true;
                else $reason = trim($verdict.'. '.$this->refusalDetail);
            }
        } catch (\Throwable $th) {
            $reason = $th->getMessage();
            \REDCap::logEvent('Auto-Save Value offline mode', 'Stood down on this page: '.$reason, '', $record, $event_id);
        }

        $this->initializeJavascriptModuleObject();

        $fields = $this->syncableFields($instrument);
        $uncovered = $this->uncoveredFields($instrument);

        // A multi-page survey shows one page's fields at a time; the rest of the
        // instrument is not on this page and must not be reported as missing.
        if ($onSurvey && is_array($survey['pageFields'])) {
            $onPage = array_flip($survey['pageFields']);
            $fields = array_intersect_key($fields, $onPage);
            $uncovered = array_intersect_key($uncovered, $onPage);
        }

        // What the database holds right now, in display format, so the browser
        // does not have to guess it from the rendered form. The two differ on a
        // form with no data yet: @DEFAULT, @SETVALUE, @TODAY and @NOW put values
        // on screen that are not in the database, and a browser that took the
        // screen as the server's word would raise a false conflict on the first
        // edit and, worse, never save the pre-filled values at all.
        $serverValues = null;
        if ($mayWrite || ($onSurvey && !is_null($record) && $record !== '')) {
            try {
                $current = $this->currentValues(array_keys($fields), $fields);
                $serverValues = [];
                foreach ($current as $field => $value) {
                    $serverValues[$field] = is_array($value) ? $value : $this->formatDisplayValue($field, $value);
                }
            } catch (\Throwable $th) {
                $serverValues = null; // the browser falls back to the screen
            }
        }

        $settings = [
            'record'        => $record,
            'eventId'       => (int) $event_id,
            'instrument'    => $instrument,
            'instance'      => (int) $this->instance,
            'user'          => $onSurvey ? 'survey' : ((defined('USERID')) ? USERID : ''),
            'projectId'     => (int) $project_id,
            'survey'        => $onSurvey,
            'surveyHash'    => $onSurvey ? $survey['hash'] : '',
            'page'          => $onSurvey ? (int) $survey['page'] : 1,
            'syncEnabled'   => $mayWrite,
            'syncReason'    => $reason,
            'flushSeconds'  => $this->flushSeconds(),
            'ttlHours'      => $this->ttlHours(),
            'showStatus'    => !$onSurvey && !$this->getProjectSetting('hide-status'),
            'syncAction'    => static::SYNC_ACTION,
            'fields'        => $fields,
            'serverValues'  => $serverValues,
            'skipFields'    => $this->unsyncableFields($instrument),
            'uncovered'     => $uncovered,
            'version'       => isset($this->VERSION) ? $this->VERSION : ''
        ];

        // a form with nothing this layer can hold (all calculated, all read-only,
        // a table of descriptives) gets no layer and no indicator
        if (!count($fields)) return;

        // JSON_HEX_TAG so a record id containing </script> cannot break out of the
        // block. Slashes stay escaped for the same reason.
        $json = json_encode($settings, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if ($json === false) return; // rather than emitting "var x = ;" and killing the page's scripts
        ?>
        <!-- Auto-Save Value offline mode: start -->
        <link rel="stylesheet" type="text/css" href="<?=$this->getUrl('css/auto-save-value.css')?>">
        <script type="text/javascript">
            var AutoSaveOfflineSettings = <?=$json?>;
            var AutoSaveValueModule = <?=$this->getJavascriptModuleObjectName()?>;
        </script>
        <script type="text/javascript" src="<?=$this->getUrl('js/auto-save-value.js')?>"></script>
        <!-- Auto-Save Value offline mode: end -->
        <?php
    }


    /**
     * Both layers arrive here. 'save' is the action-tag layer writing one field,
     * and it is reachable from a survey. 'sync' is the offline mode writing a
     * batch, and it is authenticated only: see auth-ajax-actions in config.json.
     */
    public function redcap_module_ajax($action, $payload, $project_id, $record, $instrument, $event_id, $repeat_instance, $survey_hash, $response_id, $survey_queue_hash, $page, $page_full, $user_id, $group_id) {
        if ($action == static::SYNC_ACTION) {
            return $this->syncBatch($payload, $project_id, $record, $instrument, $event_id, $repeat_instance);
        }
        if ($action == static::AUTOSAVE_ACTION) {
            // a survey request carries a hash and has no logged-in user
            $this->noauth = !empty($survey_hash) || !defined('USERID') || USERID === '';
            return $this->saveSingleValue($payload, $project_id, $record, $instrument, $event_id, $repeat_instance);
        }
        return null;
    }

    /**
     * The action-tag save, with a gate added in front of it. The body below is
     * the original module's; the reason for the gate is worth writing down.
     *
     * `save` is declared in no-auth-ajax-actions, because the action tags are
     * meant to work on surveys and a survey respondent has no login. The check
     * that follows is only that the field name exists somewhere on the
     * instrument, so an anonymous caller can write any field on any instrument
     * of any record in the project: a randomisation arm, a calculated field, a
     * form's _complete status, and even a form that offline mode refuses as
     * locked in the same process.
     *
     * That matters more here, because a project that wants only offline mode
     * gets this endpoint with it. The gate restricts the endpoint to what the
     * feature needs: a field that is a supported type, has a metadata row, and
     * carries an @AUTOSAVE tag for the mode in use. Nothing a legitimate
     * @AUTOSAVE user does is affected.
     *
     * The residual risk, which cannot be closed from here, is that an anonymous
     * caller may still write a tagged field on a record other than their own.
     * Closing it needs the framework to bind the record to the survey hash.
     */
    protected function saveSingleValue($payload, $project_id, $record, $instrument, $event_id, $repeat_instance) {
        global $Proj;

        $field = (is_array($payload) && isset($payload[0]) && is_string($payload[0])) ? $payload[0] : '';
        if (!$this->fieldMayAutoSave($field, $instrument)) {
            \REDCap::logEvent('Auto-Save Value module', 'Refused a save for a field that is not tagged for auto-save on this instrument', '', $record, $event_id);
            return 0;
        }

        $this->project_id = $project_id;
        $this->record = $record;
        $this->event_id = $event_id;
        $this->instance = $repeat_instance;
        $rtn = 0;

        try {
            if (!is_array($payload)) throw new \Exception('Unexpected payload '.$this->escape(json_encode($payload)));
            $payload = array_values($payload);

            if (!is_string($payload[0]) || !array_key_exists($payload[0], $Proj->forms[$instrument]['fields'])) throw new \Exception('Unexpected field name '.json_encode($payload[0]));
            $field = $this->escape($payload[0]);

            if (!isset($payload[1])) throw new \Exception("Field $field no value supplied");
            $value = $payload[1]; // nb do not use $this->escape() here because altering e.g. & in text values submitted to &amp; is undesirable 

            $saveArray = array(
                'dataFormat' => 'json-array', 
                'overwriteBehavior' => 'overwrite'
            );

            if ($Proj->project['require_change_reason']=='1') {
                $changeReasons = array();
                $changeReasonText = (isset($payload[2])) ? htmlspecialchars($payload[2], ENT_QUOTES) : $this->PREFIX;
                $changeReasons[$record][$event_id] = $changeReasonText;
                $saveArray['changeReasons'] = $changeReasons;
            }

            $saveData = array(
                $Proj->table_pk => $this->record,
                $field => $this->formatSaveValue($field, $value)
            );

            if (\REDCap::isLongitudinal()) {
                $saveData['redcap_event_name'] = \REDCap::getEventNames(true, false, $this->event_id);
            }

            if ($Proj->isRepeatingEvent($this->event_id)) {
                $saveData['redcap_repeat_instrument'] = '';
                $saveData['redcap_repeat_instance'] = $this->instance;
            } else if ($Proj->isRepeatingForm($this->event_id, $instrument)) {
                $saveData['redcap_repeat_instrument'] = $instrument;
                $saveData['redcap_repeat_instance'] = $this->instance;
            }

            $saveArray['data'] = array($saveData);

            $saveResult = \REDCap::saveData($saveArray);

            if (isset($saveResult['errors']) && !empty($saveResult['errors'])) {
                $detail = "Field: $field; Value: $value";
                $detail .= " \nErrors: ".print_r($saveResult['errors'], true);
                throw new \Exception($detail);
            } else {
                $rtn = 1;
            }
        } catch(\Throwable $th) {
            $title = "Auto-Save Value module";
            $detail = "Save failed: ".$th->getMessage();
            \REDCap::logEvent($title, $detail, '', $record, $event_id);
        }

        return $rtn;
    }


    public function formatSaveValue($field, $value) {
        global $Proj;
        $fieldType = $Proj->metadata[$field]['element_type'];
        $fieldValType = $Proj->metadata[$field]['element_validation_type'];

        if ($fieldType=='text' && strpos($fieldValType,'mdy')) {
            $value = \DateTimeRC::date_mdy2ymd($value);
        } else if ($fieldType=='text' && strpos($fieldValType,'dmy')) {
            $value = \DateTimeRC::date_dmy2ymd($value);
        }
        // nb do not use $this->escape() here because altering e.g. & in text values submitted to &amp; is undesirable 
        // save data gets validated and sanitised in REDCap::saveData()
        return $value;
    }

    /**
     * redcap_module_configuration_settings
     * Triggered when the system or project configuration dialog is displayed for a given module.
     * Allows dynamically modify and return the settings that will be displayed.
     * @param string $project_id, $settings


    /**
     * Only show the change reason setting on projects that ask for one. Both
     * layers use the same setting, because both write without being able to stop
     * and ask a human for a reason.
     */
    public function redcap_module_configuration_settings($project_id, $settings) {
        if (!empty($project_id)) {
            global $Proj;
            if ($Proj->project['require_change_reason']=='1') {
                foreach ($settings as $si => $sarray) {
                    if ($sarray['key']=='default-reason-for-change') {
                        $settings[$si]['hidden'] = false;
                        break;
                    }
                }
            }
        }
        return $settings;
    }


    /* ---------------------------------------------------------------- */
    /* offline mode, everything below is new                            */
    /* ---------------------------------------------------------------- */

    /**
     * Everything the data entry screen would have enforced. Returns true, or a
     * string saying why not.
     *
     * Order matters a little: check the cheap things first so a hostile caller
     * cannot use the expensive ones to probe the database.
     */
    /**
     * Pin down which event and instance a request is about, before anything
     * reads or writes. Two things arrive from the browser here and both were
     * being trusted: on a form that does not repeat, an instance number other
     * than 1 made the lock check look at an instance that does not exist, find
     * nothing, and call the locked form unlocked, while the write itself still
     * landed on the real form. On a classic project the event id is not part of
     * the write at all, so it is replaced with the project's only event.
     */
    protected function aimAt($event_id, $repeat_instance) {
        global $Proj;

        $this->event_id = $event_id;
        if (!\REDCap::isLongitudinal() && isset($Proj->firstEventId) && $Proj->firstEventId) {
            $this->event_id = $Proj->firstEventId;
        }

        $wanted = (is_numeric($repeat_instance) && (int) $repeat_instance > 0) ? (int) $repeat_instance : 1;
        $repeats = false;
        if (isset($Proj) && method_exists($Proj, 'isRepeatingEvent') && method_exists($Proj, 'isRepeatingForm')) {
            $repeats = $Proj->isRepeatingEvent($this->event_id) || $Proj->isRepeatingForm($this->event_id, $this->instrument);
        }
        $this->instance = $repeats ? $wanted : 1;
    }

    protected function checkCaller($project_id, $record, $instrument, $event_id, $instance) {
        if (!$this->instrumentIsProtected($instrument)) return 'Instrument is not enabled for offline background saving';

        $user = (defined('USERID')) ? USERID : '';
        if ($user === '') return 'No user';

        $rights = $this->rightsOf($project_id, $user);

        if (is_null($rights)) {
            // An administrator who has not been added to the project has no
            // rights row, yet REDCap lets them edit every form. Match that; the
            // event and lock checks below still apply to them.
            if (!$this->isSuperUser()) {
                $this->refusalDetail = 'REDCap returned no rights for user "'.$user.'" in project '.$project_id.'.';
                return 'No rights in this project';
            }
        } else {
            $formRight = isset($rights['forms'][$instrument]) ? (string) $rights['forms'][$instrument] : '0';
            if (!$this->formRightAllows($formRight, 'view-edit')) {
                $this->refusalDetail = 'User "'.$user.'" has form right '.$formRight.' on '.$instrument.', which does not include view and edit.';
                return 'No edit rights on this instrument';
            }

            // A completed survey response is read-only on the data entry screen
            // unless the user holds "edit survey responses".
            if (!$this->formRightAllows($formRight, 'editresp') && $this->isCompletedSurveyResponse($record, $instrument, $event_id, $instance)) {
                $this->refusalDetail = 'This is a completed survey response and user "'.$user.'" does not have "edit survey responses" on '.$instrument.'.';
                return 'No edit rights on this instrument';
            }

            // a user inside a DAG may only touch records inside that DAG
            if (!empty($rights['group_id'])) {
                $recordGroup = $this->recordGroupId($project_id, $record);
                if (is_null($recordGroup)) {
                    $this->refusalDetail = 'Neither Records::getRecordGroupId nor an export with DAGs returned a group for record "'.$record.'".';
                    return 'Could not confirm which data access group this record belongs to';
                }
                if ((string) $recordGroup !== (string) $rights['group_id']) {
                    $this->refusalDetail = 'Record is in group '.$recordGroup.', user "'.$user.'" is currently in group '.$rights['group_id'].'.';
                    return 'Record belongs to another data access group';
                }
            }
        }

        if (!$this->eventHasInstrument($event_id, $instrument)) return 'That event does not have this instrument';

        if ($this->recordIsLocked($project_id, $record)) return 'Form is locked';
        if ($this->formIsLocked($project_id, $record, $event_id, $instrument, $instance)) return 'Form is locked';
        if ($this->formIsSigned($project_id, $record, $event_id, $instrument, $instance)) return 'Form is e-signed';

        return true;
    }

    /**
     * Has this survey response been completed. Only asked when the instrument is
     * a survey; if REDCap cannot tell us, assume it has, because the alternative
     * is letting a right-1 user edit a response the screen would have shown them
     * read-only.
     */
    protected function isCompletedSurveyResponse($record, $instrument, $event_id, $instance) {
        global $Proj;
        if (!isset($Proj->forms[$instrument]['survey_id']) || !$Proj->forms[$instrument]['survey_id']) return false;
        $surveyId = $Proj->forms[$instrument]['survey_id'];
        if (!class_exists('\Survey') || !method_exists('\Survey', 'isResponseCompleted')) return true;
        try {
            return (bool) \Survey::isResponseCompleted($surveyId, $record, $event_id, $instance);
        } catch (\Throwable $th) {
            return true;
        }
    }

    /**
     * The specific reason behind the last generic refusal. Only ever shown on the
     * data entry page, to a user who is already looking at the record, never
     * returned from the sync endpoint: there, a message that tells "another
     * DAG" apart from "no such record" is a way to enumerate records.
     */
    protected $refusalDetail = '';

    /**
     * Does a form-level right grant what is asked. REDCap has two encodings and
     * a live install turned up the second one: below 128 the value is the old
     * 0 none, 1 view and edit, 2 read only, 3 edit survey responses; from 128 it
     * is a bitmask, 128 marking the new scheme, 1 read only, 2 view and edit,
     * 8 edit survey responses, 16 delete. So a plain "view and edit" user shows
     * up as 130. REDCap's own helper is used when it exists, and the same rules
     * are applied by hand when it does not.
     */
    protected function formRightAllows($value, $right) {
        if (class_exists('\UserRights') && method_exists('\UserRights', 'hasDataViewingRights')) {
            try { return (bool) \UserRights::hasDataViewingRights($value, $right); } catch (\Throwable $th) {}
        }
        if (!is_numeric($value) || $value === '') return false;
        $value = (int) $value;
        if ($value < 128) {
            if ($right == 'view-edit') return $value == 1 || $value == 3;
            if ($right == 'editresp')  return $value == 3;
            return false;
        }
        if ($right == 'view-edit') return ($value & 2) == 2;
        if ($right == 'editresp')  return ($value & 2) == 2 && ($value & 8) == 8;
        return false;
    }

    /**
     * This user's rights in the project, or null if they have none. Goes through
     * the public API first; if that comes back empty, tries the class REDCap
     * itself uses, because on some versions the API omits users whose rights come
     * entirely from a role.
     */
    protected function rightsOf($project_id, $user) {
        $allRights = \REDCap::getUserRights($user);
        if (is_array($allRights) && isset($allRights[$user]) && is_array($allRights[$user])) return $allRights[$user];

        if (class_exists('\UserRights') && method_exists('\UserRights', 'getPrivileges')) {
            try {
                $priv = \UserRights::getPrivileges($project_id, $user);
                if (is_array($priv) && isset($priv[$project_id][$user]) && is_array($priv[$project_id][$user])) {
                    $row = $priv[$project_id][$user];
                    // this shape stores form rights as a string, "[form,1][other,3]"
                    if (isset($row['data_entry']) && !isset($row['forms'])) {
                        $row['forms'] = [];
                        if (preg_match_all('/\[([^,\]]+),([^\]]*)\]/', (string) $row['data_entry'], $m, PREG_SET_ORDER)) {
                            foreach ($m as $pair) $row['forms'][$pair[1]] = $pair[2];
                        }
                    }
                    return $row;
                }
            } catch (\Throwable $th) {
                // fall through to null
            }
        }

        return null;
    }

    protected function isSuperUser() {
        if (defined('SUPER_USER') && SUPER_USER) return true;
        if (class_exists('\UserRights') && method_exists('\UserRights', 'isSuperUserNotImpersonator')) {
            try { return (bool) \UserRights::isSuperUserNotImpersonator(); } catch (\Throwable $th) {}
        }
        return false;
    }

    /**
     * Which DAG a record sits in, or null if we could not tell.
     *
     * Deliberately not a query against redcap_data. Record data has been spread
     * across redcap_data1..N for several versions now, with the project's actual
     * table named in redcap_projects.data_table, so a hardcoded table name comes
     * back empty on a sharded project and looks exactly like "no DAG". Silently
     * wrong is worse than not knowing, so go through supported calls and return
     * null when neither works.
     */
    /**
     * The event id arrives in the request and is used to pick which event to read
     * and write. Left unchecked, a user with edit rights could aim the write at
     * an event in another arm, or one where this instrument is not designated,
     * and the lock check would ask about an event that does not exist. On a
     * classic non-longitudinal project there is nothing to check.
     */
    protected function eventHasInstrument($event_id, $instrument) {
        global $Proj;
        if (!\REDCap::isLongitudinal()) return true;
        if (!isset($Proj->eventsForms) || !is_array($Proj->eventsForms)) return true; // cannot tell, and this is not the safety net
        if (!array_key_exists($event_id, $Proj->eventsForms)) return false;
        return in_array($instrument, (array) $Proj->eventsForms[$event_id]);
    }

    protected function recordGroupId($project_id, $record) {
        if (class_exists('\Records') && method_exists('\Records', 'getRecordGroupId')) {
            // false means "no group", which is an answer, not a failure
            $group = \Records::getRecordGroupId($project_id, $record);
            if ($group !== false && !is_null($group) && $group !== '') return $group;
        }

        // second route: ask getData for the group and translate the unique name back
        $data = \REDCap::getData([
            'project_id'              => $project_id,
            'records'                 => [$record],
            'fields'                  => [$this->recordIdField()],
            'exportDataAccessGroups'  => true,
            'return_format'           => 'array'
        ]);

        $unique = null;
        if (isset($data[$record]) && is_array($data[$record])) {
            foreach ($data[$record] as $eventData) {
                if (is_array($eventData) && !empty($eventData['redcap_data_access_group'])) {
                    $unique = $eventData['redcap_data_access_group'];
                    break;
                }
            }
        }
        if (is_null($unique)) return null;

        $names = \REDCap::getGroupNames(true); // group_id => unique name
        if (!is_array($names)) return null;
        foreach ($names as $id => $name) {
            if ($name === $unique) return $id;
        }

        return null;
    }

    protected function recordIdField() {
        global $Proj;
        return $Proj->table_pk;
    }

    /**
     * Is this form locked, or e-signed.
     *
     * These two read REDCap's own tables, which is the weakest thing in this
     * module: the schema is internal and undocumented, and guessing column names
     * that turn out to be wrong would either throw on every page or, worse,
     * quietly match nothing and report every locked form as unlocked.
     *
     * So do not guess. Ask the database which columns the table actually has and
     * build the condition from the ones that are there. A table that does not
     * exist, or that has no recognisable record column, is treated as "cannot
     * tell", and the caller refuses. Fail closed, loudly, and say which it was.
     */
    protected function formIsLocked($project_id, $record, $event_id, $instrument, $instance) {
        return $this->lockTableSays('redcap_locking_data', $project_id, $record, $event_id, $instrument, $instance);
    }

    /**
     * "Lock entire record" lives in its own table, redcap_locking_records, keyed
     * by project, record and arm. The column discovery below reduces the query to
     * project and record, which is the right question: a record locked on any
     * arm is not something to write to in the background.
     */
    protected function recordIsLocked($project_id, $record) {
        return $this->lockTableSays('redcap_locking_records', $project_id, $record, null, null, null);
    }

    protected function formIsSigned($project_id, $record, $event_id, $instrument, $instance) {
        return $this->lockTableSays('redcap_esignatures', $project_id, $record, $event_id, $instrument, $instance);
    }

    /** column names seen on this server, worked out once per request */
    protected $tableColumns = [];

    protected function columnsOf($table) {
        if (isset($this->tableColumns[$table])) return $this->tableColumns[$table];

        $cols = [];
        try {
            $result = $this->query('show columns from '.$table, []);
            while ($result && ($row = $result->fetch_assoc())) {
                $name = isset($row['Field']) ? $row['Field'] : reset($row);
                $cols[] = strtolower($name);
            }
        } catch (\Throwable $th) {
            $cols = []; // table missing or unreadable
        }

        $this->tableColumns[$table] = $cols;
        return $cols;
    }

    protected function lockTableSays($table, $project_id, $record, $event_id, $instrument, $instance) {
        $cols = $this->columnsOf($table);
        if (empty($cols)) throw new \Exception("Cannot read $table, so cannot confirm the form is unlocked");

        // the record column has been called both of these across versions
        $recordCol = in_array('record', $cols) ? 'record' : (in_array('record_id', $cols) ? 'record_id' : null);
        if (is_null($recordCol)) throw new \Exception("Do not recognise the shape of $table, so cannot confirm the form is unlocked");

        $where = [];
        $args = [];
        if (in_array('project_id', $cols)) { $where[] = 'project_id = ?'; $args[] = $project_id; }
        $where[] = $recordCol.' = ?'; $args[] = $record;
        if (!is_null($event_id) && in_array('event_id', $cols))  { $where[] = 'event_id = ?';  $args[] = $event_id; }

        // a null form_name would still count, defensively; whole-record locks are
        // in fact a separate table, see recordIsLocked
        if (!is_null($instrument) && in_array('form_name', $cols)) { $where[] = '(form_name = ? or form_name is null)'; $args[] = $instrument; }

        // instance is null for the first instance on most versions
        if (!is_null($instance) && in_array('instance', $cols)) { $where[] = 'coalesce(instance, 1) = ?'; $args[] = $instance; }

        $sql = 'select 1 from '.$table.' where '.implode(' and ', $where).' limit 1';
        $result = $this->query($sql, $args);
        return (bool) ($result && $result->fetch_assoc());
    }

    /**
     * Fields on this instrument we are prepared to write, with enough detail for
     * the browser to read them properly. Checkboxes carry their choice codes so
     * the whole group can be sent together, which is what stops a single tick
     * wiping its siblings.
     */
    protected function syncableFields($instrument) {
        return $this->classifyFields($instrument)['fields'];
    }

    /**
     * Fields the browser should mirror locally but never send. Mostly the
     * calculated ones, which the server works out for itself.
     */
    protected function unsyncableFields($instrument) {
        return $this->classifyFields($instrument)['skip'];
    }

    /**
     * Fields on this instrument offline mode is not covering, and why. Sent
     * to the browser so the reason is one console line away instead of a guess.
     * Two radio fields on a real project were left out because of a conditional
     * @READONLY and it took a round of "it does not work for radios" to find.
     */
    protected function uncoveredFields($instrument) {
        return $this->classifyFields($instrument)['uncovered'];
    }

    protected $classified = [];

    protected function classifyFields($instrument) {
        if (isset($this->classified[$instrument])) return $this->classified[$instrument];

        global $Proj;
        $out = ['fields' => [], 'writable' => [], 'skip' => [], 'uncovered' => []];
        if (!isset($Proj->forms[$instrument]['fields'])) return $this->classified[$instrument] = $out;

        foreach ($Proj->forms[$instrument]['fields'] as $field => $label) {
            if ($field == $Proj->table_pk) continue;
            if (!isset($Proj->metadata[$field])) continue; // no metadata row, so nothing to hold
            $meta = $Proj->metadata[$field];
            $type = $meta['element_type'];

            // The form status dropdown has a metadata row like any other field,
            // but it is REDCap's own statement about the form and is set when
            // the form is saved, so it is not ours to write in the background.
            if ($field === $instrument.'_complete') {
                $out['uncovered'][$field] = 'form status, set when the form is saved';
                continue;
            }

            if ($type == 'calc' || $this->hasCalculatedActionTag($meta)) {
                // mirrored so a restore looks complete, never sent: the server
                // works these out for itself
                $out['skip'][] = $field;
                $out['uncovered'][$field] = 'calculated by REDCap';
                continue;
            }

            if ($type == 'descriptive' || $type == 'section_header') continue; // nothing to hold

            if (!in_array($type, static::$SyncableFieldTypes)) {
                $out['uncovered'][$field] = 'field type "'.$type.'" is not supported';
                continue;
            }

            // ontology lookups are text fields carrying a service in element_enum.
            // The box shows a label and stores a code, so reading .val() would save
            // the wrong thing. Same reason AutoSaveValue leaves them alone.
            if ($type == 'text' && trim((string) $meta['element_enum']) !== '') {
                $out['uncovered'][$field] = 'ontology lookup';
                continue;
            }

            // the randomisation result is REDCap's to write, once, and saveData
            // refuses it afterwards; a draft carrying it would be refused every flush
            if (in_array($field, $this->randomizationFields(), true)) {
                $out['uncovered'][$field] = 'randomisation field';
                continue;
            }

            // Action tags are read after @IF has been resolved for this record,
            // the same way REDCap itself reads them. Matching the raw annotation
            // would treat @IF([x]='1', @READONLY, '') as read-only for everyone.
            $annotation = $this->resolvedAnnotation($meta);

            // @READONLY fields are not the user's to edit. REDCap drives them,
            // usually with @SETVALUE, and it will put its own value back the
            // moment the page recalculates. Writing them means fighting REDCap
            // forever and raising a conflict on every flush. @READONLY-FORM is
            // the same thing scoped to exactly where this layer runs.
            //
            // These two stay out of the browser's list but are still accepted by
            // the endpoint (see 'writable'). Neither is a permission: REDCap does
            // not enforce them on save, and a field that became read-only through
            // an @IF after the page loaded still holds a value the user typed
            // while it was editable.
            if ($this->annotationHasTag($annotation, '@READONLY') || $this->annotationHasTag($annotation, '@READONLY-FORM')) {
                $out['uncovered'][$field] = 'read-only on this page (@READONLY)';
                $out['writable'][$field] = $this->fieldEntry($meta);
                continue;
            }

            // a @RICHTEXT box keeps its content inside CKEditor until the form is
            // submitted, so the underlying textarea reads stale. Mirroring it
            // would quietly drop whatever the user actually wrote.
            if ($this->annotationHasTag($annotation, '@RICHTEXT')) {
                $out['uncovered'][$field] = 'rich text editor (@RICHTEXT)';
                $out['writable'][$field] = $this->fieldEntry($meta);
                continue;
            }

            $out['fields'][$field] = $this->fieldEntry($meta);
            $out['writable'][$field] = $out['fields'][$field];
        }

        return $this->classified[$instrument] = $out;
    }

    /** what the browser needs to know to read a field properly */
    protected function fieldEntry($meta) {
        $type = $meta['element_type'];
        $entry = ['type' => $type];
        if (isset($meta['element_label'])) {
            // for the panels, which should name a field the way the form does
            $label = trim(preg_replace('/\s+/', ' ', strip_tags((string) $meta['element_label'])));
            if ($label !== '') $entry['label'] = mb_substr($label, 0, 80);
        }

        if ($type == 'checkbox') {
            // strval because php turns numeric array keys into ints, and the
            // browser builds element names by gluing the code onto the field
            $entry['choices'] = array_map('strval', array_keys(parseEnum($meta['element_enum'])));
        }

        $validation = (string) $meta['element_validation_type'];
        if ($validation !== '') $entry['validation'] = $validation;

        return $entry;
    }

    /**
     * Everything the endpoint will write: the browser's list plus the fields
     * left off it for display reasons rather than because writing them is wrong.
     */
    protected function writableFields($instrument) {
        return $this->classifyFields($instrument)['writable'];
    }

    /** the randomisation target fields, one per model; empty when the project has none */
    protected $randomizationFields = null;

    protected function randomizationField() {
        return $this->randomizationFields();
    }

    protected function randomizationFields() {
        global $Proj;
        if (is_array($this->randomizationFields)) return $this->randomizationFields;
        $out = [];
        if (!isset($Proj->project['randomization']) || !$Proj->project['randomization']) return $this->randomizationFields = $out;
        if (!class_exists('\Randomization') || !method_exists('\Randomization', 'getRandomizationAttributes')) return $this->randomizationFields = $out;

        // a project may have several randomisation models, each with its own rid
        $rids = [];
        try {
            $result = $this->query('select rid from redcap_randomization where project_id = ?', [$Proj->project_id]);
            while ($result && ($row = $result->fetch_assoc())) $rids[] = $row['rid'];
        } catch (\Throwable $th) {
            $rids = [null]; // could not ask, so let REDCap pick its first model
        }
        // randomisation switched on but no model set up yet: nothing to exclude

        foreach ($rids as $rid) {
            try {
                // signature is (rid, project_id); null rid means the first model
                $attr = \Randomization::getRandomizationAttributes($rid, $Proj->project_id);
                if (is_array($attr) && !empty($attr['targetField'])) $out[] = $attr['targetField'];
            } catch (\Throwable $th) {}
        }
        return $this->randomizationFields = array_values(array_unique($out));
    }

    /**
     * The field annotation with any @IF(...) resolved for the record on screen.
     * REDCap's own helper does it; if this server does not have it, or it
     * throws, fall back to the raw text, which is the behaviour before this fix.
     */
    protected function resolvedAnnotation($meta) {
        $raw = (string) $meta['misc'];
        if ($raw === '' || stripos($raw, '@IF') === false) return $raw;
        if (!class_exists('\Form') || !method_exists('\Form', 'replaceIfActionTag')) return $raw;
        try {
            $resolved = \Form::replaceIfActionTag($raw, $this->project_id, $this->record, $this->event_id, $this->instrument, $this->instance);
            return is_string($resolved) ? $resolved : $raw;
        } catch (\Throwable $th) {
            return $raw;
        }
    }

    /** is this action tag present, as a whole word rather than a substring */
    protected function hasActionTag($meta, $tag) {
        return $this->annotationHasTag((string) $meta['misc'], $tag);
    }

    protected function annotationHasTag($annotation, $tag) {
        $annotation = strtoupper((string) $annotation);
        return (bool) preg_match('/(^|[^A-Z0-9_-])'.preg_quote($tag, '/').'($|[^A-Z0-9_-])/', $annotation);
    }

    protected function hasCalculatedActionTag($meta) {
        $annotation = strtoupper((string) $meta['misc']);
        return strpos($annotation, '@CALCTEXT') !== false || strpos($annotation, '@CALCDATE') !== false;
    }

    /**
     * "Protect every instrument" or "protect the ones listed". An install from
     * before the scope setting existed has no value for it and keeps behaving as
     * it did: only the listed instruments. Nothing configured means nothing
     * protected, deliberately: the module should not start writing to a form
     * nobody asked it to.
     */
    protected function instrumentIsProtected($instrument) {
        global $Proj;
        if (!is_string($instrument) || $instrument === '') return false;

        $scope = $this->getProjectSetting('protect-scope');
        if ($scope === 'all') {
            // still has to be a real instrument on this project
            if (isset($Proj->forms) && is_array($Proj->forms)) return array_key_exists($instrument, $Proj->forms);
            return true;
        }

        $chosen = $this->getProjectSetting('protected-instrument');
        if (is_string($chosen) && $chosen !== '') $chosen = [$chosen]; // a single value need not be a list
        if (!is_array($chosen)) return false; // nothing configured yet, stay out of the way

        $chosen = array_filter($chosen, function($v) { return $v !== null && $v !== ''; });
        if (count($chosen) == 0) return false;

        return in_array($instrument, $chosen);
    }

    protected function flushSeconds() {
        $seconds = (int) $this->getProjectSetting('flush-seconds');
        if ($seconds < static::MIN_FLUSH_SECONDS) return static::DEFAULT_FLUSH_SECONDS;
        return min($seconds, 300);
    }

    protected function ttlHours() {
        $hours = (int) $this->getProjectSetting('draft-ttl-hours');
        if ($hours < 1 || $hours > 168) return static::DEFAULT_TTL_HOURS;
        return $hours;
    }

    /**
     * Take a batch of changed fields and write the ones nobody else has touched.
     *
     * The browser sends what it believes the server currently holds for each field
     * alongside the new value. If the server disagrees, someone edited the record
     * while this batch was sitting in the queue, so we refuse that field and hand
     * both values back for the user to sort out. Everything else in the batch still
     * saves, because there is no reason to punish nine good fields for one clash.
     *
     * Every check in here fails closed. If we cannot work out what the server
     * currently holds, we refuse the whole batch rather than assume it is empty
     * and overwrite live data.
     */
    protected function syncBatch($payload, $project_id, $record, $instrument, $event_id, $repeat_instance) {
        $this->project_id = $project_id;
        $this->record = $record;
        $this->instrument = $instrument;
        $this->aimAt($event_id, $repeat_instance);
        $event_id = $this->event_id; // what the request said is no longer trusted below

        $result = ['saved' => [], 'conflicts' => [], 'rejected' => [], 'notes' => [], 'errors' => [], 'terminal' => false];
        try {
            if (is_null($record) || $record === '') throw new \Exception('No record to save to');

            $allowed = $this->checkCaller($project_id, $record, $instrument, $event_id, $this->instance);
            if ($allowed !== true) throw new \Exception($allowed);

            if (!is_array($payload) || !isset($payload['changes']) || !is_array($payload['changes']) || count($payload['changes']) == 0) {
                throw new \Exception('Nothing to save');
            }
            if (count($payload['changes']) > static::MAX_FIELDS_PER_BATCH) {
                throw new \Exception('Too many fields in one batch');
            }

            $syncable = $this->writableFields($instrument);

            // work out which fields we are even willing to look at before going
            // anywhere near the database
            $wanted = [];
            foreach ($payload['changes'] as $field => $change) {
                if (!array_key_exists($field, $syncable)) {
                    $result['rejected'][$field] = 'not a saveable field on this instrument';
                    continue;
                }
                if (!is_array($change) || !array_key_exists('value', $change)) {
                    $result['rejected'][$field] = 'no value supplied';
                    continue;
                }
                if (!array_key_exists('seen', $change)) {
                    // the browser must tell us what it thought was there. Without it
                    // we would be writing blind, so refuse rather than guess.
                    $result['rejected'][$field] = 'no baseline supplied';
                    continue;
                }
                if ($syncable[$field]['type'] != 'checkbox') {
                    // a scalar field takes a scalar. An array here would be
                    // stringified to the literal "Array" a few frames later, and
                    // would also slip past the concurrency check.
                    if (is_array($change['value']) || is_object($change['value'])) {
                        $result['rejected'][$field] = 'value must be a single value for this field type';
                        continue;
                    }
                    if (is_array($change['seen']) || is_object($change['seen'])) {
                        $result['rejected'][$field] = 'baseline must be a single value for this field type';
                        continue;
                    }
                }

                if ($syncable[$field]['type'] == 'checkbox') {
                    // A checkbox writes every choice in the group as a 1 or a 0, so a
                    // malformed value here does not save the wrong thing, it wipes
                    // the whole group. Insist on the shape rather than coercing it.
                    if (!is_array($change['value']) || !is_array($change['seen'])) {
                        $result['rejected'][$field] = 'checkbox values must be sent as a list of codes';
                        continue;
                    }
                    // The browser tells us which choices it could actually see. Any
                    // choice hidden by @HIDECHOICE, or added to the codebook after
                    // this page was cached, is left alone rather than written as a 0.
                    $change['choices'] = $this->visibleChoices($syncable[$field], $change);
                    if (count($change['choices']) == 0) {
                        $result['rejected'][$field] = 'no known choices for this checkbox';
                        continue;
                    }
                }
                $wanted[$field] = $change;
            }

            if (count($wanted) == 0) return $result;

            $onServer = $this->currentValues(array_keys($wanted), $syncable); // throws if it cannot tell
            $toSave = [];

            foreach ($wanted as $field => $change) {
                $held = array_key_exists($field, $onServer)
                    ? $onServer[$field]
                    : ($syncable[$field]['type'] == 'checkbox' ? [] : '');

                // Compare like with like. The server may hold a ticked choice this
                // page never rendered, and that is not somebody else's edit, so it
                // must not raise a conflict and must not be written away either.
                if ($syncable[$field]['type'] == 'checkbox' && is_array($held)) {
                    $visible = $change['choices'];
                    $held = array_values(array_filter($held, function($code) use ($visible) {
                        return in_array((string) $code, $visible);
                    }));
                }

                // the browser reads the box, so a date arrives as 13-08-2026 while
                // the server holds 2026-08-13. Comparing those raw makes every
                // populated date field look like somebody else's edit, forever.
                $seen = $this->offlineFormatSaveValue($field, $change['seen']);

                if (!$this->sameValue($seen, $held)) {
                    // The server has moved on from what the browser saw, but if it
                    // moved to exactly this value there is nothing to argue about:
                    // most often our own earlier request landed late, after the
                    // browser had given up on it and sent again.
                    $wanted = $this->offlineFormatSaveValue($field, $change['value']);
                    if ($syncable[$field]['type'] == 'checkbox') $wanted = array_map('strval', (array) $change['value']);
                    if ($this->sameValue($wanted, $held)) {
                        $result['saved'][] = $field;
                        continue;
                    }
                    $result['conflicts'][] = [
                        'field'  => $field,
                        'mine'   => $change['value'],
                        'theirs' => $this->formatDisplayValue($field, $held)
                    ];
                    continue;
                }

                $toSave[$field] = $change;
            }

            if (count($toSave)) {
                $outcome = $this->writeValues($toSave, $syncable);
                $result['saved'] = array_values(array_unique(array_merge($result['saved'], $outcome['saved'])));
                $result['notes'] = $outcome['notes'];
                foreach ($outcome['failed'] as $field => $why) {
                    $result['rejected'][$field] = $why;
                }
            }
        } catch (\Throwable $th) {
            // Only messages this class raised deliberately are safe to show. A
            // raw exception, or a message that distinguishes "another DAG" from
            // "no such record", turns the endpoint into a way to enumerate
            // records the caller is not allowed to know exist.
            $result['errors'][] = $this->isTerminal($th->getMessage())
                ? $th->getMessage()
                : 'The change could not be saved. The study team can find the reason in the project logs.';
            // Retrying will not help if the answer is "you are not allowed" or
            // "the form is locked". The client needs to be able to tell that
            // apart from a wifi drop and say so, instead of sitting on "queued"
            // for the rest of the shift.
            $result['terminal'] = $this->isTerminal($th->getMessage());
            \REDCap::logEvent('Auto-Save Value', 'Background save refused: '.$th->getMessage(), '', $record, $event_id);
        }

        return $result;
    }

    protected function isTerminal($message) {
        foreach (['No user', 'No rights', 'No edit rights', 'data access group', 'Form is locked', 'Form is e-signed', 'not enabled for offline background saving', 'No record to save to', 'Cannot read', 'Do not recognise the shape', 'does not have this instrument'] as $phrase) {
            if (strpos($message, $phrase) !== false) return true;
        }
        return false;
    }

    /**
     * What the server holds right now for these fields, in the same shape the
     * browser sends: a string for most things, an array of ticked codes for a
     * checkbox.
     *
     * Throws rather than returning an empty set, because "I could not find the
     * data" and "the data is empty" must not be treated the same way. Confusing
     * them is how a concurrency check silently stops working.
     */
    protected function currentValues($fields, $syncable = []) {
        global $Proj;

        $data = \REDCap::getData([
            'project_id'    => $Proj->project_id,
            'records'       => [$this->record],
            'events'        => [$this->event_id],
            'fields'        => $fields,
            'return_format' => 'array'
        ]);

        $eventData = $this->locateEventData($data);
        if (is_null($eventData)) {
            // getData restricted to one event returns nothing at all for a
            // record with no data in that event, which looks the same as a record
            // that does not exist. Only the second is a failure, so ask again
            // without the event restriction before deciding.
            if ($this->recordExists()) $eventData = [];
            else throw new \Exception('Could not read the current values for this record');
        }

        $values = [];
        foreach ($fields as $field) {
            // an empty checkbox is an empty list, not an empty string. Shape
            // matters: the concurrency check compares shape as well as content.
            $empty = (isset($syncable[$field]) && $syncable[$field]['type'] == 'checkbox') ? [] : '';
            if (!array_key_exists($field, $eventData)) { $values[$field] = $empty; continue; }
            $raw = $eventData[$field];

            if (is_array($raw)) { // checkbox, code => 0/1
                $ticked = [];
                foreach ($raw as $code => $on) {
                    if ($on == '1') $ticked[] = (string) $code;
                }
                sort($ticked);
                $values[$field] = $ticked;
            } else if (is_array($empty)) {
                // metadata says checkbox but the stored value is scalar, which
                // happens when a field's type was changed under a live project
                $values[$field] = ($raw === '' || is_null($raw)) ? [] : [(string) $raw];
            } else {
                $values[$field] = (string) $raw;
            }
        }

        return $values;
    }

    /** does this record exist at all, in any event */
    protected function recordExists() {
        global $Proj;
        if (class_exists('\Records') && method_exists('\Records', 'recordExists')) {
            try { return (bool) \Records::recordExists($Proj->project_id, $this->record); } catch (\Throwable $th) {}
        }
        $data = \REDCap::getData([
            'project_id'    => $Proj->project_id,
            'records'       => [$this->record],
            'fields'        => [$Proj->table_pk],
            'return_format' => 'array'
        ]);
        return is_array($data) && isset($data[$this->record]);
    }

    /**
     * getData nests differently for repeating forms, so dig out the right level
     * rather than assuming. Returns null when nothing matches, and the caller
     * treats that as a hard failure.
     */
    protected function locateEventData($data) {
        global $Proj;
        if (!isset($data[$this->record])) return null;   // no such record: a hard failure
        $recordData = $data[$this->record];

        $repeatingEvent = isset($Proj) && method_exists($Proj, 'isRepeatingEvent') && $Proj->isRepeatingEvent($this->event_id);
        $repeatingForm  = isset($Proj) && method_exists($Proj, 'isRepeatingForm') && $Proj->isRepeatingForm($this->event_id, $this->instrument);

        if ($repeatingEvent || $repeatingForm) {
            // repeating data lives only under repeat_instances; a repeating
            // event keys its forms under '', a repeating form under its name
            $bucket = $repeatingEvent ? '' : $this->instrument;
            if (isset($recordData['repeat_instances'][$this->event_id][$bucket][$this->instance])) {
                return $recordData['repeat_instances'][$this->event_id][$bucket][$this->instance];
            }
            // the record exists and this is an instance nobody has saved yet:
            // every field is empty, which is an answer rather than a failure
            return [];
        }

        if (isset($recordData[$this->event_id])) return $recordData[$this->event_id];

        // record exists, this event has no data yet
        return [];
    }

    /**
     * The choice codes we are allowed to touch on this checkbox: the ones the
     * browser says it rendered, narrowed to the ones that really are choices on
     * the field. A page cached before a codebook change must not be able to
     * invent a choice, and must not be able to zero one it never showed.
     */
    protected function visibleChoices($spec, $change) {
        $known = array_map('strval', $spec['choices']);
        $claimed = isset($change['choices']) && is_array($change['choices']) ? array_map('strval', $change['choices']) : null;

        if (is_null($claimed)) {
            // an older client that does not send its choice list. Fall back to the
            // codes it has actually mentioned, which is the most we can justify.
            $claimed = array_merge(
                array_map('strval', $change['value']),
                array_map('strval', $change['seen'])
            );
        }

        return array_values(array_unique(array_filter($claimed, function($code) use ($known) {
            return in_array($code, $known);
        })));
    }

    /**
     * Two values are the same only if they are the same shape and the same
     * content. The earlier version coerced a non-array to an empty array before
     * comparing, so a payload claiming a baseline of [] matched a server value of
     * anything at all and the concurrency check simply switched off. A stale
     * client can send that by accident after a field is changed from checkbox to
     * text in the codebook, so this is not only an attack.
     */
    protected function sameValue($a, $b) {
        if (is_array($a) !== is_array($b)) return false;
        if (is_array($a)) {
            $a = array_map('strval', $a);
            $b = array_map('strval', $b);
            sort($a);
            sort($b);
            return $a == $b;
        }
        if (is_object($a) || is_object($b)) return false;
        return ((string) $a) === ((string) $b);
    }

    /**
     * Date and datetime conversion for the offline mode.
     *
     * The form shows what the project is configured to show, d-m-y here, and
     * saveData only ever accepts y-m-d. The action-tag layer hands this to
     * DateTimeRC::date_dmy2ymd(), and for a plain date that is fine. For a
     * datetime it is an open question whether that helper keeps the time part,
     * and it could not be answered without a REDCap server. Rather than ship a
     * guess on a field type this project uses sixteen times, the offline mode
     * does the conversion itself, explicitly, both halves.
     */
    protected function offlineFormatSaveValue($field, $value) {
        global $Proj;
        if (is_array($value) || is_object($value)) return $value; // a checkbox set, nothing to reformat
        $value = (string) $value;
        if ($value === '') return $value;

        $meta = $Proj->metadata[$field];
        if ($meta['element_type'] != 'text') return $value;

        $validation = (string) $meta['element_validation_type'];
        if (strpos($validation, 'date') !== 0) return $value; // date_* and datetime_* only

        $order = null;
        if (strpos($validation, 'dmy') !== false) $order = 'dmy';
        else if (strpos($validation, 'mdy') !== false) $order = 'mdy';
        else if (strpos($validation, 'ymd') !== false) $order = 'ymd';
        if (is_null($order)) return $value;

        list($date, $time) = $this->splitDateTime($value);
        $bits = preg_split('/[-\/.]/', $date);
        if (count($bits) != 3) return $value; // not a shape we recognise, hand it over untouched

        if ($order == 'dmy')      $ymd = $bits[2].'-'.$bits[1].'-'.$bits[0];
        else if ($order == 'mdy') $ymd = $bits[2].'-'.$bits[0].'-'.$bits[1];
        else                      $ymd = $bits[0].'-'.$bits[1].'-'.$bits[2];

        return $ymd.$time;
    }

    /** splits "14-08-2026 13:45" into the date and " 13:45", time may be absent */
    protected function splitDateTime($value) {
        $value = trim($value);
        if (strpos($value, ' ') === false) return [$value, ''];
        list($date, $rest) = explode(' ', $value, 2);
        return [$date, ' '.trim($rest)];
    }

    /**
     * The other direction. A conflict panel offers to put the server's value into
     * the box, so it has to be handed over in the format the box expects.
     */
    protected function formatDisplayValue($field, $value) {
        global $Proj;
        if (is_array($value) || (string) $value === '') return $value;

        $type = $Proj->metadata[$field]['element_type'];
        $validation = (string) $Proj->metadata[$field]['element_validation_type'];
        if ($type != 'text') return $value;

        $mdy = strpos($validation, 'mdy') !== false;
        $dmy = strpos($validation, 'dmy') !== false;
        if (!$mdy && !$dmy) return $value;

        // done by hand rather than through DateTimeRC because only the two
        // ...2ymd helpers are safe to rely on across versions
        $time = '';
        $date = (string) $value;
        if (strpos($date, ' ') !== false) {
            list($date, $rest) = explode(' ', $date, 2);
            $time = ' '.$rest;
        }
        $bits = explode('-', $date);
        if (count($bits) != 3) return $value;

        return ($dmy ? $bits[2].'-'.$bits[1].'-'.$bits[0] : $bits[1].'-'.$bits[2].'-'.$bits[0]).$time;
    }

    /**
     * Turn our field/value pairs into the flat row saveData expects, then write.
     *
     * If REDCap rejects anything it rejects the whole row, so on failure we go
     * back through one field at a time. That way a single bad date cannot cost
     * the user the other nine fields, and we can tell them exactly which one is
     * the problem.
     */
    protected function writeValues($changes, $syncable) {
        $outcome = ['saved' => [], 'failed' => [], 'notes' => []];

        $attempt = $this->saveRow($changes, $syncable);
        if (empty($attempt['errors'])) {
            $outcome['saved'] = array_keys($changes);
            // REDCap stored the row but had something to say about it, most often a
            // soft range. Pass it on as a note, not as a failure: reporting a stored
            // value as refused is how the client ends up permanently out of step.
            if (!empty($attempt['warnings'])) $outcome['notes'] = $attempt['warnings'];
            return $outcome;
        }

        // something in the batch was refused, so find out what
        foreach ($changes as $field => $change) {
            $single = $this->saveRow([$field => $change], $syncable);
            if (empty($single['errors'])) {
                $outcome['saved'][] = $field;
                if (!empty($single['warnings'])) $outcome['notes'] = array_merge($outcome['notes'], $single['warnings']);
            } else {
                $outcome['failed'][$field] = $this->plainRefusal($single['errors']);
                \REDCap::logEvent('Auto-Save Value', "Rejected $field: ".implode('; ', array_map('strval', $single['errors'])), '', $this->record, $this->event_id);
            }
        }

        return $outcome;
    }

    /**
     * saveData explains a refusal in its import voice: "10-1","rand_dob",
     * "2020-02-31","Invalid date format. (NOTE: Dates must be imported here
     * only in Y-M-D format ...)". A research assistant at a bedside needs the
     * middle of that, in plain words. The full text still goes to the log.
     */
    protected function plainRefusal($errors) {
        $out = [];
        foreach ((array) $errors as $error) {
            $error = (string) $error;
            // "record","field","value","message" -> message
            if (preg_match('/^"[^"]*","[^"]*","[^"]*","(.*)"$/s', trim($error), $m)) $error = $m[1];
            $error = preg_replace('/\s*\(NOTE:.*?\)\s*/s', ' ', $error);
            $error = trim(preg_replace('/\s+/', ' ', $error));
            if (stripos($error, 'Invalid date') !== false) $error = 'This is not a valid date.';
            if ($error !== '') $out[] = $error;
        }
        return count($out) ? implode(' ', array_unique($out)) : 'REDCap did not accept this value.';
    }

    protected function saveRow($changes, $syncable) {
        global $Proj;

        $row = [$Proj->table_pk => $this->record];

        foreach ($changes as $field => $change) {
            $value = $change['value'];

            if ($syncable[$field]['type'] == 'checkbox') {
                // json-array wants one key per choice. Writing only the ticked ones
                // would mean a box could be ticked but never unticked, so send the
                // whole group, but only the part of the group this page could see.
                $ticked = array_map('strval', $value);
                foreach ($change['choices'] as $code) {
                    // REDCap names a negative code's column with an underscore in
                    // place of the minus: field____1 for code -1, as export does
                    $row[$field.'___'.str_replace('-', '_', (string) $code)] = in_array((string) $code, $ticked) ? '1' : '0';
                }
            } else {
                $row[$field] = $this->offlineFormatSaveValue($field, $value);
            }
        }

        if (\REDCap::isLongitudinal()) {
            $row['redcap_event_name'] = \REDCap::getEventNames(true, false, $this->event_id);
        }

        if ($Proj->isRepeatingEvent($this->event_id)) {
            $row['redcap_repeat_instrument'] = '';
            $row['redcap_repeat_instance'] = $this->instance;
        } else if ($Proj->isRepeatingForm($this->event_id, $this->instrument)) {
            $row['redcap_repeat_instrument'] = $this->instrument;
            $row['redcap_repeat_instance'] = $this->instance;
        }

        $saveArray = [
            'dataFormat' => 'json-array',
            'overwriteBehavior' => 'overwrite',
            'data' => [$row]
        ];

        if ($Proj->project['require_change_reason'] == '1') {
            $reason = trim((string) $this->getProjectSetting('default-reason-for-change'));
            if ($reason === '') $reason = $this->PREFIX;
            $saveArray['changeReasons'] = [$this->record => [$this->event_id => $reason]];
        }

        $saved = \REDCap::saveData($saveArray);

        return [
            'errors'   => isset($saved['errors']) ? $saved['errors'] : [],
            'warnings' => isset($saved['warnings']) ? $saved['warnings'] : []
        ];
    }

}
