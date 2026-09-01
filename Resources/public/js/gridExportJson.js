/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


if (typeof opendxp.object.gridexport === "object") {
  opendxp.registerNS("opendxp.object.gridexport.datadirector_json");
  opendxp.object.gridexport.datadirector_json = Class.create(opendxp.element.gridexport.abstract, {
    name: "datadirector_json",
    text: t("pim.grid-export.json"),
    warningText: '',

    dataportUrl: null,

    getDownloadUrl: function (fileHandle) {
      var panel = Ext.getCmp("opendxp_panel_tabs").getActiveTab();
      panel.object.search.exportProcessUrl = Routing.generate('opendxp_admin_dataobject_dataobjecthelper_doexport');

      var classId = panel.object.search.classId;
      var gridConfigId = panel.object.search.settings.gridConfigId;

      var fields = panel.object.search.fieldObject;

      var url;
      Ext.Ajax.request({
        url: '/admin/SylphenDataBridge/import/generic-grid-export',
        params: {
          gridConfig: gridConfigId,
          type: 'json',
          classId: classId,
          fields: JSON.stringify(fields),
          fileHandle: fileHandle
        },
        method: 'post',
        async: false,
        success: function (response) {
          response = Ext.decode(response.responseText);
          if (!(response && response.success)) {
          } else {
            url = response.downloadUrl;
          }
        }
      });

      return url;
    },

    getObjectSettingsContainer: function () {
      var panel = Ext.getCmp("opendxp_panel_tabs").getActiveTab();
      panel.object.search.exportProcessUrl = '/admin/SylphenDataBridge/import/do-nothing';

      var enableInheritance = new Ext.form.Checkbox({
        fieldLabel: t('enable_inheritance'),
        name: 'enableInheritance',
        value: true,
        labelWidth: 200
      });

      return new Ext.form.FieldSet({
        title: t('object_settings'),
        items: [
          enableInheritance
        ]
      });
    }
  });

  opendxp.globalmanager.get("opendxp.object.gridexport").push(new opendxp.object.gridexport.datadirector_json());
}

