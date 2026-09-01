/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


if (typeof opendxp.object.gridexport === "object") {
  opendxp.registerNS("opendxp.object.gridexport.datadirector_import_excel");
  opendxp.object.gridexport.datadirector_import_excel = Class.create(opendxp.element.gridexport.abstract, {
    name: "datadirector_import_excel",
    text: t("pim.grid-export.import_excel"),
    warningText: t('pim.grid-export.import_excel.warning'),

    dataportId: null,
    url: null,

    getDownloadUrl: function (fileHandle) {
      panel.object.search.exportProcessUrl = Routing.generate('opendxp_admin_dataobject_dataobjecthelper_doexport');

      opendxp.plugin.Pim.plugin.runDataport(this.dataportId, this.url);
      return 'about:blank';
    },

    getObjectSettingsContainer: function () {
      var panel = Ext.getCmp("opendxp_panel_tabs").getActiveTab();

      panel.object.search.exportPrepare = function (settings, exportType) {
        if (formPanel.getForm().isValid()) {
          formPanel.up('window').closeAction = 'hide';
          formPanel.getForm().submit({
            url: '/admin/SylphenDataBridge/import/grid-import-prepare',
            waitMsg: t('pim.manual.importForm.waitMsg'),
            success: function (fp, action) {
              this.url = action.result.runUrl;
              this.dataportId = action.result.dataportId;

              panel.object.search.exportProcessUrl = Routing.generate('opendxp_admin_dataobject_dataobjecthelper_doexport');

              opendxp.plugin.Pim.plugin.runDataport(this.dataportId, this.url);
            }.bind(this)
          });
        }
      };

      var classId = panel.object.search.classId;

      var formPanel = Ext.create('Ext.form.Panel', {
        url: '/admin/SylphenDataBridge/import/grid-import-prepare',
        padding: 15,
        layout: {
          type: 'vbox',
          align: 'stretch'
        },
        items: [{
            xtype: 'hidden',
            name: 'csrfToken',
            value: opendxp.settings['csrfToken'],
          },
          {
            xtype: 'fileuploadfield',
            fieldLabel: t('file'),
            name: 'importfile',
            allowBlank: false,
            buttonText: t('select_a_file'),
            buttonCfg: {
              iconCls: 'opendxp_icon_file'
            }
          },
          {
            xtype: 'checkbox',
            name: 'dry-run',
            boxLabel: t('pim.manual.startimport.dry-run'),
            inputValue: 1
          },
          {
            xtype: 'hidden',
            name: 'classId',
            value: classId
          },
          {
            xtype: 'hidden',
            name: 'locale',
            value: panel.object.search.gridLanguage
          }
        ]
      });

      return formPanel;
    }
  });

  opendxp.globalmanager.get("opendxp.object.gridexport").push(new opendxp.object.gridexport.datadirector_import_excel());
}

