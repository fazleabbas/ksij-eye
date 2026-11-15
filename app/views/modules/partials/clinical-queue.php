<?php
/** Clinical queue panel. Expects $clPrefix (element id prefix), $clTitle and $clStage (JS controller name). */
defined('KSI_APP') || exit;
?>
          <div class="tq-header">
            <div class="tq-title"><?= htmlspecialchars($clTitle) ?></div>
            <div class="cl-queue-tabs" id="<?= $clPrefix ?>-q-tabs">
              <button type="button" class="cl-qtab active" data-view="queue" onclick="<?= $clStage ?>.setView('queue')">Queue</button>
              <button type="button" class="cl-qtab" data-view="recent" onclick="<?= $clStage ?>.setView('recent')">Completed by me</button>
            </div>
            <div class="tq-search-wrap">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <input type="text" class="tq-search" id="<?= $clPrefix ?>-q-search" placeholder="Search this queue: name, phone or file number…" maxlength="80" oninput="<?= $clStage ?>.searchChanged()">
            </div>
            <div id="<?= $clPrefix ?>-q-filters">
              <div class="tq-stats" id="<?= $clPrefix ?>-q-stats"></div>
              <div class="cl-queue-dates">
                <input type="date" class="filter-select" id="<?= $clPrefix ?>-q-from" title="Visit date from" onchange="<?= $clStage ?>.loadQueue()">
                <input type="date" class="filter-select" id="<?= $clPrefix ?>-q-to" title="Visit date to" onchange="<?= $clStage ?>.loadQueue()">
              </div>
            </div>
          </div>
          <div class="tq-list" id="<?= $clPrefix ?>-q-list"></div>
