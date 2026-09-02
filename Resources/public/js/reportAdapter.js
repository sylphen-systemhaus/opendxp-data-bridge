/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


opendxp.registerNS("opendxp.report.custom.definition.dataBridge");
opendxp.registerNS("opendxp.bundle.customreports.custom.definition.dataBridge");
var reportAdapter = Class.create({
    element: null,
    sourceDefinitionData: null,
    columnSettingsCallback: null,

    initialize: function (sourceDefinitionData, key, deleteControl, columnSettingsCallback) {
        this.sourceDefinitionData = sourceDefinitionData;
        this.columnSettingsCallback = columnSettingsCallback;

        var dataportStore = Ext.create('Ext.data.JsonStore', {
            fields: ['id', 'name', 'description', 'icon', 'url', 'group', 'groupIcon', 'searchField'],
            proxy: {
                type: 'ajax',
                url: '/admin/SylphenDataBridge/importconfig/can-be-executed',
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
            value: sourceDefinitionData.dataport,
            fieldLabel: t('pim.manual.statusgrid.dataport'),
            queryMode: 'local',
            allowBlank: false,
            editable: true,
            anyMatch: true,
            typeAhead: true,
            forceSelection: true,
            store: dataportStore,
            valueField: 'id',
            displayField: 'searchField',
            minChars: 1,
            width: 300,
            emptyText: t('loading') + ' ...',
            listConfig: {
                tpl: [
                    '<tpl for=".">',
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
                blur: function() {
                    this.updateGroupByMultiSelectStore(false);
                }.bind(this)
            }
        });

        this.element = new Ext.form.FormPanel({
            bodyStyle: "padding:10px;",
            autoHeight: true,
            border: false,
            tbar: deleteControl,
            listeners: {
                afterrender: function() {
                    this.updateGroupByMultiSelectStore(true);
                }.bind(this)
            },
            items: [
                dataportField,
                {
                    xtype: 'checkbox',
                    name: 'force',
                    value: sourceDefinitionData.force,
                    fieldLabel: t('pim.report.force'),
                    autoEl: {
                        tag: 'div',
                        'data-qtip': t('pim.report.force.tooltip')
                    }
                },
            ]
        });

        this.element.updateLayout();
    },

    getElement: function() {
        return this.element;
    },

    getValues: function() {
        var values = this.element.getForm().getFieldValues();
        values.type = "dataBridge";
        return values;
    },

    onSqlEditorKeyup: function() {
        clearTimeout(this._keyupTimout);

        var self = this;
        this._keyupTimout = setTimeout(function() {
            self.updateGroupByMultiSelectStore(false);
        }, 500);
    },

    updateGroupByMultiSelectStore: function(addItem) {
        this.columnSettingsCallback();
    }
});

opendxp.report.custom.definition.dataBridge = reportAdapter;
opendxp.bundle.customreports.custom.definition.dataBridge = reportAdapter;