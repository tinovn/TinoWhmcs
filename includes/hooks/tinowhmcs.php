<?php
/**
 * Tino Domain: narrow the "Phường / Xã" dropdown to the chosen "Tỉnh / Thành phố".
 * Copy to <whmcs>/includes/hooks/tinowhmcs.php. Works with the fields defined in
 * resources/domains/additionalfields.php (client cart + admin domain pages).
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/../../modules/registrars/tinowhmcs/lib/TinoApi.php';

$tinowhmcsLocationJs = function ($vars) {
    $page = isset($vars['templatefile']) ? $vars['templatefile'] : (isset($vars['filename']) ? $vars['filename'] : '');
    if (!in_array($page, ['configuredomains', 'clientsdomains', 'orders', 'ordersadd'], true)) {
        return '';
    }
    $wards = \TinoWhmcs\TinoApi::wards();
    if (!$wards) {
        return '';
    }
    $json = json_encode($wards, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    return <<<HTML
<script>
(function () {
  var W = {$json}, states = Object.keys(W);
  var firstWard = W[states[0]][0];
  var isState = function (s) { return s.options.length && s.options.length <= states.length + 1 && Array.prototype.every.call(s.options, function (o) { return !o.value || W[o.value]; }); };
  var isWard = function (s) { return s.options.length > states.length && s.options[0].value === firstWard; };
  var stateSels = [], wardSels = [];
  Array.prototype.forEach.call(document.querySelectorAll('select'), function (s) {
    if (isState(s)) stateSels.push(s); else if (isWard(s)) wardSels.push(s);
  });
  stateSels.forEach(function (stateSel, i) {
    var wardSel = wardSels[i];
    if (!wardSel) return;
    var fill = function (keep) {
      wardSel.innerHTML = '';
      (W[stateSel.value] || []).forEach(function (n) { wardSel.add(new Option(n, n, false, n === keep)); });
    };
    fill(wardSel.value);
    stateSel.addEventListener('change', function () { fill(''); });
  });
})();
</script>
HTML;
};

add_hook('ClientAreaFooterOutput', 1, $tinowhmcsLocationJs);
add_hook('AdminAreaFooterOutput', 1, $tinowhmcsLocationJs);
