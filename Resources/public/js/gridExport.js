/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


if(typeof opendxp.object.gridexport === "object") {
  opendxp.registerNS("opendxp.object.gridexport.datadirector");
  opendxp.object.gridexport.datadirector = Class.create(opendxp.element.gridexport.abstract, {
    name: "datadirector",
    text: t("pim.PIM config"),
    warningText: t('pim.grid-export.warning'),

    dataportUrl: null,
    dataportId: null,

    getDownloadUrl: function (fileHandle) {
      opendxp.plugin.Pim.plugin.runDataport(this.dataportId, this.dataportUrl.replace(/&?async=1/, '') + '&async=1&gridExport=' + fileHandle);
      return 'about:blank';
    },

    getObjectSettingsContainer: function () {
      var panel = Ext.getCmp("opendxp_panel_tabs").getActiveTab();
      var objectId = panel.object.id;

      var dataportStore = Ext.create('Ext.data.JsonStore', {
        fields: ['id', 'name', 'description', 'icon', 'url', 'group', 'groupIcon', 'searchField'],
        proxy: {
          type: 'ajax',
          url: '/admin/SylphenDataBridge/importconfig/can-be-executed',
          extraParams: {
            id: objectId,
            type: 'object',
            classId: panel.object.search.classId,
            onlyExports: 1
          },
          reader: {
            type: 'json',
            rootProperty: 'exports',
            transform: function (data) {
              Ext.each(data.dataports.exports, function (dataport) {
                dataport.group = t('export');
                dataport.groupIcon = '/bundles/opendxpadmin/img/flat-color-icons/export.svg';

                dataport.searchField = dataport.name + ' ' + dataport.id;
              });
              return data.dataports;
            }
          }
        },
        autoLoad: true,
        listeners: {
          load: function (store) {
            if (store.getCount() === 0) {
              dataportField.emptyText = t('pim.grid-export.no_dataports_found');
            } else {
              dataportField.emptyText = t('pim.grid-export.select_dataport');
            }
            if (typeof dataportField.setEmptyText !== 'undefined') {
              dataportField.setEmptyText(dataportField.emptyText);
            } else {
              dataportField.applyEmptyText();
            }
          }
        }
      });

      var dataportField = new Ext.form.ComboBox({
        name: 'dataport',
        fieldLabel: t('pim.manual.statusgrid.dataport'),
        queryMode: 'local',
        allowBlank: false,
        editable: true,
        anyMatch: true,
        typeAhead: true,
        forceSelection: true,
        store: dataportStore,
        matchFieldWidth: false,
        valueField: 'id',
        displayField: 'searchField',
        minChars: 1,
        emptyText: t('loading') + ' ...',
        listConfig: {
          tpl: [
            '<tpl for=".">',
            //'{[xindex === 1 || parent[xindex - 2].group !== values.group ? "<div style=\'padding: 5px 0;\'><img src=\'"+values.groupIcon+"\' alt=\'"+values.group+"\' style=\'vertical-align: middle\'> "+values.group+"</div>" : ""]}',
            '<div role="option" class="x-boundlist-item" title="{description}"><img src="{icon}" alt="{name}" style="vertical-align: middle"> {name} (ID {id})</div>',
            '</tpl>'
          ]
        },
        displayTpl: [
          '<tpl for=".">',
          '{name} ({id})',
          '</tpl>'
        ],
        enableKeyEvents: true,
        listeners: {
          select: function (combo, record) {
            this.dataportUrl = record.get('url') + '&locale=' + localeField.getValue();
            this.dataportId = record.get('id');
            panel.object.search.exportProcessUrl = '/admin/SylphenDataBridge/import/do-export';
          }.bind(this),
          blur: function () {
            this.up('window').on('close', function () {
              setTimeout(function () {
                panel.object.search.exportProcessUrl = Routing.generate('opendxp_admin_dataobject_dataobjecthelper_doexport');
              }, 2000);
            }, this, { single: true });
          }
        }
      });

      var localeData = [];
      for (var i = 0; i < opendxp.settings.websiteLanguages.length; i++) {
        localeData.push([opendxp.settings.websiteLanguages[i], t(opendxp.available_languages[opendxp.settings.websiteLanguages[i]])]);
      }

      var localeField = new Ext.form.ComboBox({
        fieldLabel: t('language'),
        triggerAction: "all",
        editable: true,
        selectOnFocus: true,
        queryMode: 'local',
        typeAhead: true,
        anyMatch: true,
        forceSelection: true,
        store: new Ext.data.ArrayStore({
          fields: ["key", "value"],
          data: localeData
        }),
        mode: "local",
        displayField: "value",
        valueField: "key",
        value: Ext.getCmp("opendxp_panel_tabs").getActiveTab().object.search.gridLanguage,
        listConfig: {
          tpl: [
            '<tpl for=".">',
            '<div role="option" class="x-boundlist-item"><div style="height:21px;display:inline-block;padding-left:34px;background-position: 0 0" class="opendxp_icon_language_{[values.key.toLowerCase()]}">{value}</div></div>',
            '</tpl>'
          ]
        },
        listeners: {
          select: function (combo, record) {
            var dataport = dataportField.getSelection();
            if (dataport) {
              this.dataportUrl = dataport.get('url') + '&locale=' + localeField.getValue();
              Ext.getCmp("opendxp_panel_tabs").getActiveTab().object.search.exportProcessUrl = '/admin/SylphenDataBridge/import/do-export';
            }
          }.bind(this),
          focus: function (combo) {
            combo.expand();
          }
        }
      });

      return new Ext.form.FieldSet({
        title: t('settings'),
        layout: 'fit',
        items: [
          dataportField,
          {
            xtype: 'tbspacer',
            height: 10
          },
          localeField
        ]
      });
    }
  });

  (function () {
    var user = new opendxp.user(opendxp.currentuser);
    var allowed = user.isAllowed('plugin_sylphen_data_bridge');
    if (!allowed) {
      for (var i in user.permissions) {
        if (user.permissions[i].indexOf('Dataport') > -1) {
          allowed = true;
          break;
        }
      }
    }

    if (allowed) {
      opendxp.globalmanager.get("opendxp.object.gridexport").push(new opendxp.object.gridexport.datadirector());
    }
  })();
}