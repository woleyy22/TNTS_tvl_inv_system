<?php
/** One lightweight chooser for Maintenance and Condemnation; no duplicate form inputs. */
function p72_picker_markup(string $id,string $selectId): string {
 $id=htmlspecialchars($id,ENT_QUOTES,'UTF-8');$selectId=htmlspecialchars($selectId,ENT_QUOTES,'UTF-8');
 return '<div class="p72-picker" data-picker="'.$id.'" data-select="'.$selectId.'">
  <button type="button" class="btn btn-outline p72-picker-open" aria-expanded="false" aria-controls="'.$id.'-panel">🔎 <span class="p72-picker-label">Search and select equipment</span></button>
  <section class="p72-picker-panel" id="'.$id.'-panel" hidden aria-label="Searchable equipment list">
   <label class="field"><span>Search item, ICS, course or receiving batch</span><input type="search" class="input p72-picker-search" placeholder="Type a name, ICS or batch number..." autocomplete="off"></label>
   <div class="p72-picker-toolbar"><label>Condition <select class="input p72-picker-condition"><option value="all">All conditions</option><option value="serviceable">Serviceable</option><option value="repair">For repair</option><option value="damaged">Damaged</option></select></label><span class="p72-picker-count" aria-live="polite"></span></div>
   <div class="p72-picker-results" role="list"></div>
   <nav class="p72-picker-pages" aria-label="Equipment picker pages"><button class="btn btn-outline p72-picker-prev" type="button">Previous</button><span class="p72-picker-page"></span><button class="btn btn-outline p72-picker-next" type="button">Next</button></nav>
  </section>
 </div>';
}
