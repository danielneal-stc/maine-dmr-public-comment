(function (Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.pegaCaseAutofill = {
    attach: function (context, settings) {
      var dmr = drupalSettings.dmrPublicComment || {};
      var url;

      if (dmr.nodeId) {
        // Node mode: form is embedded on a public_comment_period node.
        // Field values come from the node itself via the node data endpoint.
        url = dmr.nodeApiBase + dmr.nodeId;
      }
      else if (dmr.apiBase) {
        // Pega mode: form is accessed standalone with a ?case_id= query parameter.
        var caseId = new URLSearchParams(window.location.search).get('case_id');
        if (!caseId) return;
        url = dmr.apiBase + caseId;
      }
      else {
        return;
      }

      fetch(url)
        .then(function (response) {
          if (!response.ok) return null;
          return response.json();
        })
        .then(function (data) {
          if (!data || data.error) return;
          populateForm(data);
        })
        .catch(function (err) {
          console.error('DMR public comment autofill failed:', err);
        });
    },
  };

  function populateForm(data) {
    var set = function (selector, value) {
      var el = document.querySelector(selector);
      if (el) el.value = value || '';
    };

    set('#edit-applicant', data.applicant);
    set('#edit-town', data.town);
    set('#edit-location', data.location);
    set('#edit-comment-period', data.comment_period);

    var licenseTypeField = document.querySelector('[name="license_type"]');
    if (licenseTypeField) {
      licenseTypeField.value = data.license_type || '';
      // Use jQuery trigger so Drupal's webform conditional logic fires.
      // A plain DOM dispatchEvent won't reach jQuery-bound listeners.
      if (window.jQuery) {
        window.jQuery(licenseTypeField).trigger('change');
      } else {
        licenseTypeField.dispatchEvent(new Event('change', { bubbles: true }));
      }
    }
  }

})(Drupal, drupalSettings);
