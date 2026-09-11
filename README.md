********************************************************************************
# Offline Sync

Syed Gilani, The Kids Research Institute Australia https://www.thekids.org.au

[https://gitlab.com/pchresearch/redcap-auto-save-value](https://gitlab.com/pchresearch/redcap-auto-save-value)

Built on **Auto-Save Value** by Luke Stevens, Murdoch Children's Research
Institute https://www.mcri.edu.au

Original project: [lsgs/redcap-auto-save-value](https://github.com/lsgs/redcap-auto-save-value).
The `@AUTOSAVE` action tags below are his work. Offline
mode is added here.

********************************************************************************
## Summary

Offline mode keeps a copy of the whole form on the device where the connection
is unreliable, and saves changed values in the background. It is enabled per
instrument in the module configuration.

The `@AUTOSAVE` action tags save one tagged field at a time, the moment it
changes:
- `@AUTOSAVE` Auto-save the field's value when it is updated (in either data entry or survey mode).
- `@AUTOSAVE-FORM` Auto-save in data entry mode only, not in survey mode.
- `@AUTOSAVE-SURVEY` Auto-save in survey mode only, no in data entry mode.
- `@AUTOSAVE-FORM-HIDEICON` As `@AUTOSAVE-FORM`, but suppress the field's save icon where in data entry mode it would normally be shown.
- `@AUTOSAVE-SURVEY-SHOWICON` As `@AUTOSAVE-SURVEY`, but show the field's save icon where in survey mode it would normally be suppressed.

The two are independent: the action tags need no configuration, and offline mode
needs no tags.

********************************************************************************
## Offline Mode

Optional, off until selected in configuration, and intended for data collection
where the network drops. The action tags save one tagged field at a time;
offline mode looks after the whole selected instrument.

- Every change is written to an encrypted copy of the form in the browser
  (IndexedDB), so a reload or a closed tab does not lose it. On the next load
  the answers are offered back, to restore or discard.
- On data entry forms changed values are also saved in the background through
  `REDCap::saveData()` on a retrying queue. An indicator on the form says
  whether everything is saved, queued, or held on the device.
- Where someone else has changed the same field in the meantime, both values are
  shown and the user chooses.
- On survey pages nothing is sent to the server since a survey respondent is
  not a REDCap user. The page is held on the device and offered back after a
  reload, which covers a connection dropped between pages of a survey.
- Record locks, e-signatures, form-level rights and data access groups are
  re-checked on the server for every background save.

### Configuration

- **Protect**: all instruments, or only those listed. Nothing is protected until
  this is set.
- **Instrument**: repeatable, used when only listed instruments are protected.
- **Seconds between background saves**: default 10, minimum 3.
- **Discard on-device drafts older than**: default 12 hours, 1 to 168.
- **Hide the sync status indicator**: off by default.

### Field types

Covered: text in all validations, notes, radio, yes/no, true/false, dropdown
(including autocomplete and SQL), checkbox, and matrix rows.

Not covered: calculated fields, file and signature fields, ontology lookups,
sliders, rich text, the randomisation field and the record ID. Read-only fields
are left to REDCap, and the form completion status is never written, so a form
stays Incomplete until a user saves it. The browser console lists the uncovered
fields on an instrument with the reason for each.

********************************************************************************
## Action Tags

### Notes
- Auto-save cannot occur until the record exists for values to be saved to. This means that auto-save cannot work on the first page of a public survey, or when creating a new record.
- Only one tag is required per field: you do not need to use both `@AUTOSAVE-SURVEY` _and_ `@AUTOSAVE-SURVEY-SHOWICON`, for example. `@AUTOSAVE-SURVEY-SHOWICON` is sufficient alone for an auto-save field on a survey with icon shown.
- The auto-save tags do not operate in Draft Preview mode.
- The auto-save tags do not operate when previewing an instrument with a record data in the Online Designer using the "Preview Instrument" external module.

### Limitations

The following field types are currently **\*not supported\*** by the action tags:
- Text fields with ontology lookup
- Calculated fields (including text fields with `@CALCDATE()` and `@CALCTEXT()`)
- Checkbox
- File upload
- Signature
- Slider

### "Require Reason for Change" Option

Auto-saving on data entry forms does **\*not\*** trigger the "Require reason for change" dialog box when this option is enabled in a project. Instead, a default text value of "offline_sync" is recorded as the reason for change. There are two options for customising this text:

1. When the "Require reason for change" option is enabled in the project, the Module Configuration settings dialog shows an option where the desired default value may be entered.
2. The default text is written into a hdden HTML element in the page: `<span id="AutoSaveReason" class="d-none">offline_sync</span>`. Updating the text content of this element using a client-side script will have the altered text submitted as the "reason for change" instead.

Offline mode uses the same text. Neither it nor the action tags can stop and ask
a user for a reason, so the same string is logged every time.

********************************************************************************
