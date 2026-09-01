/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


opendxp.registerNS("opendxp.plugin.Pim.ImportConfig");
opendxp.plugin.Pim.DataportPanel = Ext.extend(Ext.TabPanel, {
    configPanel: null,
    previewPanel: null,
    manualPanel: null,

    dataport: null,

    dataportPanel: null,
    sourceConfigPanel: null,

    sourceConfigBasePanel: null,
    sourceConfigRawitemPanel: null,
    mappingPanel: null,
    versionWindow: null,

    initComponent: function () {
        this.dataport = {id: this.dataportId};
        this.configPanel = Ext.create('Ext.Panel', {
            title: t('pim.dataport_configpanel'),
            iconCls: '',
            scrollable: true,
            buttons: [{
                text: t("versions"),
                disabled: true,
                itemId: 'versionButton',
                iconCls: "opendxp_icon_versioning",
                handler: function() {
                    var versionPanel = Ext.create('opendxp.plugin.Pim.VersionPanel', {
                        dataportId: this.dataportId,
                        dataportPanel: this
                    });

                    this.versionWindow = new Ext.Window({
                        layout: 'fit',
                        title: t("versions") + ': ' + this.dataport.name,
                        width: '80%',
                        height: '80%',
                        minWidth: 600,
                        closable: true,
                        resizable: true,
                        maximizable: true,
                        draggable: true,
                        modal: true,
                        items: [versionPanel],
                        closeAction: 'destroy',
                        iconCls: 'opendxp_icon_versioning'
                    }).show();
                }.bind(this)
            },{
                text: t("save"),
                disabled: true,
                itemId: 'saveButton',
                iconCls: "opendxp_icon_save",
                handler: this.save.bind(this),
                listeners: {
                    enable: function() {
                        this.configPanel.queryById('versionButton').enable();
                    }.bind(this),
                    disable: function () {
                        this.configPanel.queryById('versionButton').disable();
                    }.bind(this)
                }
            }]
        });

        this.manualPanel = Ext.create('opendxp.plugin.Pim.ManualImport', {
            title: t('pim.manual.title'),
            dataportId: this.dataportId,
            layout: {
                type: 'vbox',
                align: 'stretch',
                pack: 'start'
            }
        });

        this.previewPanel = new opendxp.plugin.Pim.DataportPreview({
            title: t('pim.dataport_previewpanel'),
            dataportId: this.dataportId
        });


        this.configPanel.on('afterrender', function (panel) {
            this.getEl().mask();
            this.dataportPanel = this.createDataportPanel();
            this.sourceConfigPanel = Ext.create('Ext.Panel', {
                flex: 1,
                border: false
            });

            panel.add(this.dataportPanel);
            panel.add(this.sourceConfigPanel);

            opendxp.layout.refresh();

            this.populateDataportForm();
        }.bind(this));

        Ext.apply(this, {
            closable: true,
            activeTab: 0,
            tooltip: 'Dataport ID: '+this.dataportId,
            items: [this.configPanel, this.manualPanel, this.previewPanel],
            listeners: {
                'tabchange': function () {
                    opendxp.layout.refresh();
                },
                'close': function() {
                    opendxp.helpers.forgetOpenTab('dataport_' + this.dataportId);
                }
            }
        });

        opendxp.plugin.Pim.DataportPanel.superclass.initComponent.call(this);
    },

    populateDataportForm: function () {
        this.dataportPanel.getForm().getFields().each(function (item) {
            item.suspendEvents();
        });

        this.dataportPanel.getForm().load({
            url: '/admin/SylphenDataBridge/importconfig/get',
            params: {
                id: this.dataportId
            },
            failure: function (form, action) {
                this.getEl().unmask();
                opendxp.helpers.showNotification(t("error"), t(action.result.errorMessage), "error");
            }.bind(this),
            success: function (form, action) {
                this.getEl().unmask();

                form.getFields().each(function (item) {
                    item.resumeEvents();
                });

                if(!action.result.success) {
                    opendxp.helpers.showNotification(t("error"), action.result.errorMessage, "error");
                    return;
                }

                this.dataport = action.result.data;

                var isExport = this.dataport.itemClass === "0" || this.dataportPanel.getForm().findField('itemClass').getValue() === "0";

                this.manualPanel.sourceType = this.dataport.sourcetype;
                this.manualPanel.isExport = isExport;
                this.manualPanel.isMultiStepWizard = isExport && this.dataport.hasDependentDataport;
                this.manualPanel.setTitle(this.manualPanel.isExport?t('pim.manual.title.export'):t('pim.manual.title'));
                this.manualPanel.settingsPanel = this.dataportPanel;
                this.manualPanel.rebuild();

                this.previewPanel.sourceType = this.dataport.sourcetype;
                this.previewPanel.rebuild();

                this.setTitle(this.dataport.name);

                this.renderSourceConfigPanel(this.dataport.sourcetype);

                this.sourceConfigRawitemPanel.getStore().on('beforeload', function() {
                    this.configPanel.queryById('saveButton').disable();
                }.bind(this));
                this.sourceConfigRawitemPanel.getStore().on('load', function() {
                    this.configPanel.queryById('saveButton').enable();
                }.bind(this));

                var currentlyActiveTab = this.getActiveTab();
                var activeTabIndex = this.items.indexOf(currentlyActiveTab);
                if (this.mappingPanel) {
                    this.remove(this.mappingPanel);
                }
                var mappingPanelOptions = {
                    dataportId: this.dataportId,
                    title: t("pim.mapping_config"),
                    dataportPanel: this,
                    callbackLanguage: this.dataport.javascriptEngine,
                    isExport: isExport
                };

                this.mappingPanel = Ext.create('opendxp.plugin.Pim.MappingPanel', mappingPanelOptions);
                this.insert(1, this.mappingPanel);
                this.previewPanel.mappingPanel = this.mappingPanel;

                this.setActiveTab(activeTabIndex);
            }.bind(this)
        });
    },

    createDataportPanel: function () {
        if(typeof PimcoreClassesModel === 'undefined') {
            Ext.define('PimcoreClassesModel', {
                    extend: 'Ext.data.Model',
                    fields: ['id', 'name', 'supportsInheritance'],
                    idProperty: 'id',
                    root: 'fields'
                }
            );
        }

        Ext.create('Ext.data.JsonStore', {
            storeId: 'pimcoreClassesStore',
            model: 'PimcoreClassesModel',
            proxy: {
                type: 'ajax',
                url: '/admin/SylphenDataBridge/importconfig/get-pimcore-classes',
                reader: {
                    type: 'json',
                    rootProperty: 'classes'
                }

            },
            autoLoad: true
        });

        if(typeof JavascriptEngineModel === 'undefined') {
            Ext.define('JavascriptEngineModel', {
                    extend: 'Ext.data.Model',
                    fields: ['id', 'available'],
                    idProperty: 'id',
                    root: 'data'
                }
            );
        }

        var form = Ext.create('Ext.form.Panel', {
            padding: 15,
            layout: {
                type: 'vbox',
                align: 'stretch'
            },
            fieldDefaults: {
                labelAlign: 'left',
                labelWidth: 200
            },

            items: [
                {
                    xtype: 'textfield',
                    name: 'name',
                    fieldLabel: t('pim.dataport_name'),
                    allowBlank: false
                },
                {
                    xtype: 'textarea',
                    name: 'description',
                    fieldLabel: t('pim.dataport_description'),
                    grow: true
                },
                {
                    xtype: 'combo',
                    name: 'sourcetype',
                    fieldLabel: t('pim.dataport_sourcetype'),
                    forceSelection: true,
                    editable: false,
                    store: Ext.create('Ext.data.Store', {
                        fields: ['code', 'label'],
                        data: [
                            { code: 'xml', label: 'XML / HTML' },
                            { code: 'csv', label: 'CSV' },
                            { code: 'json', label: 'JSON' },
                            { code: 'excel', label: 'Excel' },
                            { code: 'fixed-length', label: t('pim.dataport_sourcetype.fixed_length_file') },
                            { code: 'pimcore', label: t('pim.dataport_sourcetype.pimcore_elements') },
                            { code: 'report', label: t('pim.dataport_sourcetype.pimcore_reports') },
                            { code: 'grid', label: t('pim.dataport_sourcetype.pimcore_grid_config') },
                            { code: 'files', label: t('pim.dataport_sourcetype.files') },
                            { code: 'object-wizard', label: t('pim.dataport_sourcetype.object-wizard') }
                        ]
                    }),
                    displayField: 'label',
                    valueField: 'code',
                    value: 'xml',
                    listeners: {
                        'change': function(combo, newValue, oldValue) {
                            if (newValue === 'report' && typeof opendxp.report.broker === 'undefined' && (typeof opendxp.bundle.customreports === 'undefined' || typeof opendxp.bundle.customreports.broker === 'undefined')) {
                                Ext.MessageBox.alert(t('error'), t('pim.dataport_sourcetype.pimcore_reports.install_report_bundle'));
                            }
                            this.renderSourceConfigPanel(newValue);
                        }.bind(this)
                    }
                },
                {
                    xtype: 'combo',
                    name: 'itemClass',
                    fieldLabel: t('pim.dataport_targetclass'),
                    queryMode: 'local',
                    anyMatch: true,
                    editable: true,
                    forceSelection: true,
                    store: 'pimcoreClassesStore',
                    valueField: 'id',
                    displayField: 'name',
                    minChars: 0,
                    listConfig: {
                        tpl: [
                            '<tpl for=".">',
                            '{[xindex === 1 || parent[xindex - 2].group !== values.group ? "<div style=\'padding: 5px 10px\'>"+values.group+"</div>" : ""]}',
                            '<div role="option" class="x-boundlist-item">&nbsp;&nbsp;{name}</div>',
                            '</tpl>'
                        ]
                    },
                    listeners: {
                        'change': function(combo, newValue, oldValue) {
                            this.renderSourceConfigPanel(this.dataportPanel.getForm().findField('sourcetype').getValue());
                        }.bind(this),
                        focus: function (combo) {
                            setTimeout(function () {
                                if (!combo.isExpanded) {
                                    combo.expand();
                                }
                            }, 100);
                        }
                    }
                }
            ]
        });

        return form;
    },

    renderSourceConfigPanel: function (sourcetype) {
        if(!this.dataport) {
            return;
        }

        this.sourceConfigPanel.removeAll();

        this.sourceConfigBasePanel = null;
        this.sourceConfigRawitemPanel = null;

        switch (sourcetype) {
            case 'xml':
                this.renderXmlSourceConfigPanel();
                break;
            case 'csv':
                this.renderCsvSourceConfigPanel();
                break;
            case 'json':
                this.renderJsonSourceConfigPanel();
                break;
            case 'excel':
                this.renderExcelSourceConfigPanel();
                break;
            case 'pimcore':
                this.renderPimcoreSourceConfigPanel();
                break;
            case 'report':
                this.renderReportSourceConfigPanel();
                break;
            case 'grid':
                this.renderGridSourceConfigPanel();
                break;
            case 'files':
                this.renderFilesSourceConfigPanel();
                break;
            case 'fixed-length':
                this.renderFixedLengthFileSourceConfigPanel();
                break;
            case 'object-wizard':
                this.renderObjectWizardSourceConfigPanel();
                break;
        }

        var form = this.sourceConfigBasePanel.getForm();
        if (this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Asset") {
            form.findField("optimizeInheritance").setHidden(true);
            form.findField("skipVersioning").setHidden(false);
        } else if (this.dataportPanel.getForm().findField('itemClass').getValue() === "0") {
            form.findField("mode").setHidden(true);
            form.findField("skipVersioning").setHidden(true);
            form.findField("optimizeInheritance").setHidden(true);
        } else {
            form.findField("optimizeInheritance").setHidden(false);
            form.findField("skipVersioning").setHidden(true);
        }

        if (!opendxp.globalmanager.get("user").isAllowed('plugin_sylphen_data_bridge_admin_permission') && !opendxp.globalmanager.get("user").isAllowed('Dataport ' + this.dataport.id + ' Configuration')) {
            this.dataportPanel.getForm().getFields().each(function (field) {
                field.setReadOnly(true);
            });

            this.sourceConfigBasePanel.getForm().getFields().each(function (field) {
                field.setReadOnly(true);
            });

            this.sourceConfigRawitemPanel.on('beforeedit', function () {
                return false;
            });

            this.sourceConfigRawitemPanel.getDockedItems()[0].setVisible(false);

            this.configPanel.queryById('saveButton').hide();
        }
    },

    renderXmlSourceConfigPanel: function () {
        var additionalColumns = this.getColumnConfigForSourcetype('xml');
        var readerFields = [
            { name: 'fieldNo', allowBlank: false, type: 'integer' },
            { name: 'sort', allowBlank: false },
            { name: 'name', allowBlank: false }
        ];

        Ext.each(additionalColumns, function (column) {
            readerFields.push({name: column.dataIndex});
        });

        readerFields.push({name: 'multiValues', type: 'boolean'});
        readerFields.push({name: 'data1', persist: false});

        if (typeof XmlImportconfigModel === 'undefined') {
            Ext.define('XmlImportconfigModel', {
                extend: 'Ext.data.Model',
                fields: readerFields,
                idProperty: 'fieldNo',
                root: 'fields'
            });
        }

        var store = new Ext.data.JsonStore({
            model: 'XmlImportconfigModel',
            proxy: {
                type: 'ajax',
                url: '/admin/SylphenDataBridge/importconfig/get-rawitemfield-config/' + this.dataport.id,
                reader: {
                    type: 'json',
                    rootProperty: 'fields',
                    messageProperty: 'errorMessage'
                }

            },
            listeners: {
                load: function () {
                    Ext.Ajax.request({
                        url: '/admin/SylphenDataBridge/importconfig/get-demo-data',
                        params: {
                            dataportId: this.dataportId
                        },
                        success: function (response) {
                            response = Ext.decode(response.responseText);

                            var store = this.sourceConfigRawitemPanel.getStore();
                            Ext.each(response.data, function (value) {
                                var record = store.findRecord('fieldNo', value.fieldNo, 0, false, true, true)

                                if (record) {
                                    record.set('data1', value.value);
                                }
                            }.bind(this));

                            this.sourceConfigRawitemPanel.getView().refresh();
                        }.bind(this)
                    });
                }.bind(this)
            },
            autoLoad: true
        });

        var columnConfig = [];
        columnConfig.push({
            header: "#",
            width: 50,
            dataIndex: 'fieldNo',
            renderer: function (value, metaData, record) {
                if (record.get('sort') === 'DESC') {
                    metaData.tdAttr = 'data-qtip="' + t('pim.dataport-fields.sorting-direction.desc') + '"';
                    value += ' <img src="/bundles/opendxpadmin/img/flat-color-icons/alphabetical_sorting_za.svg" height="18" width="18" style="height:18px;vertical-align:middle">';
                } else {
                    metaData.tdAttr = 'data-qtip="' + t('pim.dataport-fields.sorting-direction.asc') + '"';
                }
                return value;
            }
        });
        columnConfig.push({
            header: t('pim.dataport-fields-name'),
            width: 'auto',
            dataIndex: 'name',
            flex: 1,
            renderer: function (value, metaData, record) {
                if(!value) {
                    return '';
                }

                var mappingRecords = this.mappingPanel.getStore().queryBy(function () { return true; }).getRange();
                var isMapped = false;
                var minOneFieldIsMapped = false;
                Ext.each(mappingRecords, function (mappingRecord) {
                    if(mappingRecord.get('field')) {
                        minOneFieldIsMapped = true;
                    }

                    if (record.get('fieldNo') === mappingRecord.get('field')) {
                        isMapped = true;
                        return false;
                    }

                    var mappingRecordSettings = mappingRecord.get('settings');
                    if(mappingRecordSettings.calculation) {
                        minOneFieldIsMapped = true;
                        if(this.dataport.javascriptEngine === 'php' && mappingRecordSettings.calculation.indexOf('$params[\'rawItemData\'][\''+ value+'\']') > -1) {
                            isMapped = true;
                            return false;
                        } else if(this.dataport.javascriptEngine === 'v8js' && (mappingRecordSettings.calculation.indexOf('params[\'rawItemData\'][\'' + value + '\']') > -1 || mappingRecordSettings.calculation.indexOf('params.rawItemData.' + value) > -1)) {
                            isMapped = true;
                            return false;
                        }
                    }
                }.bind(this));

                if (!isMapped && minOneFieldIsMapped) {
                    metaData.tdAttr = 'data-qtip="' + t('pim.mapping.variables.variable.not_in_use') + '"';
                    return '<s>' + Ext.util.Format.htmlEncode(value) + '</s>';
                }
                return Ext.util.Format.htmlEncode(value);
            }.bind(this),
            editor: {
                xtype: 'textfield',
                allowBlank: false,
                handleMouseEvents: true,
                renderer: function (value) {
                    return Ext.util.Format.htmlEncode(value);
                },
                listeners: {
                    render: function (cmp) {
                        Ext.get(cmp.getInputId()).on('mousedown', function (e) {
                            e.stopPropagation();
                        });
                    }
                }
            }
        });

        Ext.each(additionalColumns, function (column) {
            columnConfig.push(column);
        });

        columnConfig.push({
            header: t('pim.dataport-fields-multivalues'),
            width: 'auto',
            dataIndex: 'multiValues',
            xtype: 'checkcolumn'
        });
        columnConfig.push({
            header: t('pim.dataport-fields-ex1'),
            dataIndex: 'data1',
            width: 'auto',
            flex: 1,
            renderer: function (value, metaData, record) {
                if(typeof value === 'undefined') {
                    return '';
                }
                return Ext.util.Format.htmlEncode(value);
            }
        });

        var autoCreate = function (response) {
            response = Ext.decode(response.responseText);

            if (typeof response.config !== 'undefined' && response.config.fields.length > 0) {
                if (response.config.itemxpath) {
                    this.sourceConfigBasePanel.getForm().findField('itemxpath').setValue(response.config.itemxpath);
                }

                var store = this.sourceConfigRawitemPanel.getStore();
                Ext.each(response.config.fields, function (field) {
                    if (store.findExact('name', field.name) === -1 && store.findExact('xpath', field.xpath) === -1) {
                        this.onAddDataport();
                        var record = this.sourceConfigRawitemPanel.getSelectionModel().getSelection()[0];
                        record.set({
                            name: field.name,
                            xpath: field.xpath,
                            multiValues: !!field.multiValues,
                        });
                    }
                }.bind(this));
            } else {
                var uploadWindow = Ext.create('Ext.Window', {
                    layout: {
                        type: 'vbox',
                        align: 'stretch'
                    },
                    title: t('pim.dataport.auto_create.window.title'),
                    width: 500,
                    height: 300,
                    modal: true,
                    items: Ext.create('Ext.form.Panel', {
                        padding: 15,
                        itemId: 'uploadForm',
                        layout: {
                            type: 'vbox',
                            align: 'stretch'
                        },
                        items: [{
                            xtype: 'fileuploadfield',
                            allowBlank: false,
                            fieldLabel: t('pim.manual.importForm.uploadLabel'),
                            name: 'file',
                            buttonText: t('select_a_file'),
                            buttonCfg: {
                                iconCls: 'opendxp_icon_file'
                            },
                        }, {
                            xtype: 'hidden',
                            name: 'sourceType',
                            value: this.dataportPanel.getForm().findField('sourcetype').getValue(),
                        }, {
                            xtype: 'hidden',
                            name: 'itemxpath',
                            value: this.sourceConfigBasePanel.getForm().findField('itemxpath').getValue(),
                        }, {
                            xtype: 'hidden',
                            name: 'dataportId',
                            value: this.dataportId,
                        }, {
                            xtype: 'hidden',
                            name: 'csrfToken',
                            value: opendxp.settings['csrfToken'],
                        }]
                    }),
                    buttons: [{
                        text: t('pim.dataport.auto_create.window.button'),
                        handler: function () {
                            if (uploadWindow.queryById('uploadForm').getForm().isValid()) {
                                uploadWindow.queryById('uploadForm').getForm().submit({
                                    url: '/admin/SylphenDataBridge/importconfig/auto-create-rawdata-fields',
                                    waitMsg: t('pim.manual.importForm.waitMsg'),
                                    success: function (fp, response) {
                                        if (uploadWindow) {
                                            uploadWindow.close();
                                        }

                                        autoCreate(response.response);
                                    }.bind(this),
                                    failure: function (form, action) {
                                        if (action.result.msg) {
                                            Ext.MessageBox.alert(t('error'), t('pim.manual.importForm.failure') + action.result.msg);
                                        }
                                    }.bind(this)
                                });
                            }
                        }.bind(this)
                    }]
                });
                uploadWindow.show();
            }
        }.bind(this);

        this.sourceConfigRawitemPanel = Ext.create('Ext.grid.Panel', {
            title: '',
            store: store,
            columns: {
                defaults: {
                    menuDisabled : true,
                    sortable: false
                },
                items: columnConfig
            },
            flex: 1,
            border: false,
            frame: false,
            autoScroll: true,
            layout: 'fit',
            minHeight:300,
            selModel: {
                selType: 'rowmodel',
                mode: 'MULTI',
                ignoreRightMouseSelection: true
            },
            multiSelect: true,
            dockedItems: [{
                xtype: 'toolbar',
                dock: 'top',
                items: [
                {
                    xtype: 'label',
                    html: t('pim.rawdatafields'),
                    cls: 'x-panel-header-title-default x-panel-header-default',
                    style: 'border:none;'
                },{
                    text: t('add'),
                    handler: this.onAddDataport.bind(this),
                    iconCls: "opendxp_icon_add"
                },
                '-',
                {
                    text: t('delete'),
                    handler: function () {
                        this.onDeleteDataport();
                    }.bind(this),
                    iconCls: "opendxp_icon_delete"
                },
                '-',
                {
                    text: t('pim.rawdatafields.bulk_edit'),
                    iconCls: "opendxp_icon_edit",
                    handler: function () {
                        var displayField = {
                            xtype: "displayfield",
                            region: "north",
                            hideLabel: true,
                            border: false,
                            value: t('pim.rawdatafields.bulk_edit.description') + ':<br>' + Ext.Array.filter(Ext.Array.map(columnConfig, function(column) {
                                return column.dataIndex;
                            }), function(dataIndex) {
                                return dataIndex !== 'data1';
                            }).join(',')
                        };

                        var data = [];
                        store.each(function (rec) {
                            var row = [];
                            Ext.Array.each(columnConfig, function(column) {
                                if(column.dataIndex === 'data1') {
                                    return true;
                                }
                                row.push(rec.get(column.dataIndex));
                            });
                            data.push(row);
                        });

                        data = Ext.util.CSV.encode(data);

                        var textarea = new Ext.form.TextArea({
                            region: "center",
                            border: false,
                            value: data
                        });

                        var bulkEditWindow = new Ext.Window({
                            width: 800,
                            height: 500,
                            title: t('pim.rawdatafields.bulk_edit'),
                            iconCls: "opendxp_icon_edit",
                            layout: "fit",
                            modal: true,
                            resizable: true,
                            items: [new Ext.Panel({
                                layout: "border",
                                padding: '0 10',
                                items: [displayField, textarea]
                            })],
                            buttons: [
                                {
                                    text: t('apply'),
                                    iconCls: "opendxp_icon_save",
                                    handler: function () {
                                        store.removeAll();
                                        var content = textarea.getValue();
                                        if (content.length > 0) {
                                            var csvData = Ext.util.CSV.decode(content);

                                            for (var i = 0; i < csvData.length; i++) {
                                                var row = csvData[i];

                                                this.onAddDataport();
                                                var record = this.sourceConfigRawitemPanel.getSelectionModel().getSelection()[0];

                                                Ext.Array.each(columnConfig, function (column, index) {
                                                    record.set(column.dataIndex, row[index])
                                                });
                                            }
                                        }

                                        bulkEditWindow.close();
                                    }.bind(this)
                                },
                                {
                                    text: t('cancel'),
                                    iconCls: "opendxp_icon_empty",
                                    handler: function () {
                                        bulkEditWindow.close();
                                    }
                                }
                            ]
                        }).show();
                    }.bind(this)
                },
                '->',
                {
                    text: t('pim.dataport.auto_create'),
                    handler: function() {
                        Ext.Ajax.request({
                            url: '/admin/SylphenDataBridge/importconfig/auto-create-rawdata-fields',
                            method: 'post',
                            params: {
                                sourceType: this.dataportPanel.getForm().findField('sourcetype').getValue(),
                                file: this.sourceConfigBasePanel.getForm().findField('file').getValue(),
                                itemxpath: this.sourceConfigBasePanel.getForm().findField('itemxpath').getValue(),
                                dataportId: this.dataportId
                            },
                            success: autoCreate
                        });
                    }.bind(this),
                    iconCls: "opendxp_icon_clear_cache"
                }]
            }],
            listeners: {
                beforeedit: function (editor, context) {
                    context.grid.getView().getPlugin('gridviewdragdrop').disable();
                },
                canceledit: function (editor, context) {
                    context.grid.getView().getPlugin('gridviewdragdrop').enable();
                },
                edit: function (editor, e) {
                    if (!e.record.get('name') && e.field === 'xpath' && e.value !== '') {
                        var isValid = true;

                        store.each(function (record) {
                            if (record.get('name') === e.value && record.get('fieldNo') !== e.record.get('fieldNo')) {
                                isValid = false;
                                return false;
                            }
                        });

                        if (isValid) {
                            e.record.set('name', e.value);
                        }
                    }

                    this.onAddDataport(false);

                    e.grid.getView().getPlugin('gridviewdragdrop').enable();
                }.bind(this),
                validateedit: function (editor, e) {
                    var isValid = true;

                    store.each(function (record) {
                        if (e.value !== '' && record.get(e.field) === e.value && ['name','xpath'].indexOf(e.field) > -1 && e.record.get('fieldNo') !== record.get('fieldNo')) {
                            isValid = false;
                            return false;
                        }
                    });

                    if (!isValid) {
                        opendxp.helpers.showNotification(t("error"), t('pim.dataport-fields.duplicate'), "error");
                    }

                    return isValid;
                }.bind(this),
                celldblclick: function (grid, cell, cellIndex, record) {
                    if (columnConfig[cellIndex].dataIndex === 'data1') {
                        opendxp.helpers.copyStringToClipboard(record.get('data1'));
                        opendxp.helpers.showNotification(t("info"), t('pim.mapping.copied_to_clipboard_mapping'), 'info');
                    }
                },
                cellclick: function (grid, tdElement, cellIndex, record) {
                    if (columnConfig[cellIndex].dataIndex === 'fieldNo') {
                        if (record.get('sort') === 'ASC') {
                            record.set('sort', 'DESC');
                        } else {
                            record.set('sort', 'ASC');
                        }
                    }
                },
                itemcontextmenu: function(view, record, item, index, e) {
                    var menu = new Ext.menu.Menu();

                    var selectedRows = this.sourceConfigRawitemPanel.getSelectionModel().getSelection();
                    var clickedItemIsSelected = false;
                    if (selectedRows.length > 0) {
                        Ext.each(selectedRows, function (selectedRecord, index) {
                            if (record.get('fieldNo') === selectedRecord.get('fieldNo')) {
                                clickedItemIsSelected = true;
                                return false;
                            }
                        });
                    } else {
                        clickedItemIsSelected = true;
                    }

                    var records = [];
                    if (clickedItemIsSelected) {
                        records = selectedRows.reverse();
                    }

                    if (records.length === 0) {
                        records = selectedRows.length > 0 ? selectedRows : [record];
                    }

                    menu.add(new Ext.menu.Item({
                        text: t('pim.dataport.move-to-top'),
                        icon: "/bundles/opendxpadmin/img/flat-color-icons/up.svg",
                        handler: function (menuItem, e) {
                            Ext.each(records, function (record) {
                                store.data.remove(record);
                                store.data.insert(0, record);
                            });
                        }.bind(this),
                    }));

                    if (!clickedItemIsSelected) {
                        menu.add(new Ext.menu.Item({
                            text: t('pim.dataport.move-to-selection').replace('%s', selectedRows[selectedRows.length - 1].get('name')),
                            iconCls: "opendxp_icon_table_row",
                            handler: function (menuItem, e) {
                                store.data.remove(record);
                                store.data.insert(store.indexOf(selectedRows[selectedRows.length - 1]) + 1, record);
                            }.bind(this),
                        }));
                    }

                    if (record.get('sort') === null || record.get('sort') === 'ASC') {
                        menu.add(new Ext.menu.Item({
                            text: t('pim.dataport.sort-descending'),
                            icon: "/bundles/opendxpadmin/img/flat-color-icons/alphabetical_sorting_za.svg",
                            handler: function (menuItem, e) {
                                Ext.each(records, function (record) {
                                    record.set('sort', 'DESC');
                                });
                            }.bind(this),
                        }));
                    } else if (record.get('sort') === 'DESC') {
                        menu.add(new Ext.menu.Item({
                            text: t('pim.dataport.sort-ascending'),
                            icon: "/bundles/opendxpadmin/img/flat-color-icons/alphabetical_sorting_az.svg",
                            handler: function (menuItem, e) {
                                Ext.each(records, function (record) {
                                    record.set('sort', 'ASC');
                                });
                            }.bind(this),
                        }));
                    }

                    menu.add(new Ext.menu.Item({
                        text: t('add'),
                        iconCls: "opendxp_icon_add",
                        handler: this.onAddDataport.bind(this),
                    }));

                    menu.add(new Ext.menu.Item({
                        text: t('copy'),
                        iconCls: "opendxp_icon_copy",
                        handler: function () {
                            Ext.each(records, function (record) {
                                if (selectedRows.length === 1) {
                                    this.sourceConfigRawitemPanel.getSelectionModel().select(record);
                                } else {
                                    this.sourceConfigRawitemPanel.getSelectionModel().deselectAll();
                                }

                                var addedRecord = this.onAddDataport();
                                var name = record.get('name').replace(/(.*)(\d+)$/, function (input, namePrefix, trailingDigits) {
                                    return namePrefix + (parseInt(trailingDigits, 10) + 1);
                                });

                                addedRecord.set('name', name);

                                var column = record.get('xpath');
                                addedRecord.set('xpath', column);
                            }.bind(this));
                        }.bind(this)
                    }));

                    menu.add(new Ext.menu.Item({
                        text: t('delete'),
                        iconCls: "opendxp_icon_delete",
                        handler: function () {
                            this.onDeleteDataport(record);
                        }.bind(this)
                    }));

                    menu.showAt(e.getXY());

                    e.stopEvent();
                }.bind(this)
            },
            plugins: [Ext.create('Ext.grid.plugin.CellEditing', { clicksToEdit: 2 })],
            viewConfig: {
                plugins: {
                    ptype: 'gridviewdragdrop',
                    pluginId: 'gridviewdragdrop',
                    dragText: t('pim.dataport.change-order-hint')
                }
            }
        });

        var xpathStore = Ext.create('Ext.data.JsonStore', {
            fields: ['item'],
            autoload: true,
            proxy: {
                type: 'ajax',
                url: '/admin/SylphenDataBridge/importconfig/get-xpath-suggestions',
                reader: {
                    type: 'json',
                    rootProperty: 'items'
                }
            },
            listeners: {
                beforeload: function(store, operation) {
                    var el = itemXpathField.inputEl.dom;
                    var rng, cursorPosition=-1;
                    if (typeof el.selectionStart=="number") {
                        cursorPosition=el.selectionStart;
                    } else if (document.selection && el.createTextRange){
                        rng=document.selection.createRange();
                        rng.collapse(true);
                        rng.moveStart("character", -el.value.length);
                        cursorPosition=rng.text.length;
                    }

                    operation.setParams({
                        value: itemXpathField.getValue(),
                        cursorPosition: cursorPosition,
                        dataport: this.dataportId,
                        file: this.sourceConfigBasePanel.getForm().findField('file').getValue(),
                    });

                    return true;
                }.bind(this)
            }
        });

        var itemXpathField = new Ext.form.field.ComboBox({
            name: 'itemxpath',
            fieldLabel: t('pim.dataport_xml_itemxpath'),
            value: this.getSourceconfig('itemxpath'),
            hideTrigger: true,
            typeAhead: true,
            minChars: 1,
            tpl: Ext.create('Ext.XTemplate',
                '<tpl for=".">',
                '<li class="x-boundlist-item">{value}',
                '<tpl if="label"> ({label})</tpl>',
                '</li>',
                '</tpl>'
            ),
            displayField: 'value',
            valueField: 'value',
            store: xpathStore
        });

        var fileInput = Ext.create('Ext.form.field.TextArea', {
            name: 'file',
            fieldLabel: t('pim.dataport.file'),
            value: this.getSourceconfig('file'),
            hidden: true,
            fieldStyle: 'background: url(/bundles/opendxpadmin/img/flat-color-icons/target.svg) right 5px center/20px no-repeat transparent !important;'
        });

        var editorId = Ext.id();
        var editor;
        var fieldConfig = {
            fieldLabel: t('pim.dataport.file'),
            name: 'fileEditor',
            value: '<div id="' + editorId + '" style="height:14px;width:100%;line-height:21px;background: url(/bundles/opendxpadmin/img/flat-color-icons/target.svg) right 5px center/20px no-repeat transparent !important;"></div>',
            fieldCls: 'x-form-text-default x-form-trigger-wrap-default',
            fieldStyle: 'padding-right: 0',
            style: 'opacity: 1',
            listeners: {
                render: function (el) {
                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                        ddGroup: "element",

                        getTargetFromEvent: function (e) {
                            return this.getEl();
                        },

                        onNodeOver: function (target, dd, e, data) {
                            data = data.records[0].data;

                            if (data.elementType == "asset") {
                                return Ext.dd.DropZone.prototype.dropAllowed;
                            }
                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                        },

                        onNodeDrop: function (target, dd, e, data) {
                            data = data.records[0].data;

                            if (data.elementType == "asset") {
                                editor.setValue(data.path);
                                return true;
                            }
                            return false;
                        }.bind(this)
                    });

                    this.getEl().on('dblclick', function () {
                        if (editor.getValue().length < 512) {
                            Ext.Ajax.request({
                                url: '/admin/element/get-subtype',
                                params: {
                                    id: editor.getValue(),
                                    type: 'asset'
                                },
                                success: function (response) {
                                    var res = Ext.decode(response.responseText);
                                    if (res.success) {
                                        opendxp.helpers.openElement(res.id, res.type, res.subtype);
                                    }
                                }
                            });
                        }
                    }.bind(this));
                },
                afterrender: function (cmp) {
                    editor = ace.edit(editorId);
                    editor.setTheme('ace/theme/chrome');

                    if(fileInput.getValue().indexOf("{{") === -1 && fileInput.getValue().indexOf("{%") === -1 && fileInput.getValue().indexOf("\n") > -1) {
                        editor.session.setMode('ace/mode/sh');
                    } else {
                        editor.session.setMode('ace/mode/twig');
                    }

                    var languageTools = ace.require("ace/ext/language_tools");
                    editor.completers = [languageTools.snippetCompleter, languageTools.textCompleter, {
                        getCompletions: function (editor, session, pos, prefix, callback) {
                            var variables = [];

                            var mappingRecords = this.mappingPanel.getStore().queryBy(function () { return true; }).getRange();
                            Ext.each(mappingRecords, function (mappingRecord) {
                                if (['__result_callback', '__result_action', '__init_action'].indexOf(mappingRecord.get('attributeKey')) > -1 || mappingRecord.get('attributeKey').indexOf('__virtual_') > -1) {
                                    return true;
                                }
                                var name = '';
                                if (mappingRecord.get('attributeKey').toLowerCase() !== mappingRecord.get('attributeName').toLowerCase()) {
                                    name = ' -> ' + mappingRecord.get('attributeName');
                                }
                                variables.push({ caption: '{{ ' + mappingRecord.get('attributeKey') + ' }}' + name, value: mappingRecord.get('attributeKey') + ' }}' });
                            });

                            variables.push({ caption: 'SFTP', value: 'sftp://user:password@hostname/path/to/files' });
                            variables.push({ caption: 'FTP', value: 'ftp://user:password@hostname/path/to/files' });
                            variables.push({ caption: 'FTPS', value: 'ftps://user:password@hostname/path/to/files' });
                            variables.push({ caption: t('pim.dataport.file.suggest.http_basic_auth'), value: 'https://user:password@example.org/import-file' });
                            variables.push({ caption: t('pim.dataport.file.suggest.http_post'), value: 'curl -X POST\n' +
                                    '  --header \'Content-Type: application/json\'\n' +
                                    '  --data \'{ "param1": "value1", "param2":"value2"}\' \n' +
                                    '  https://example.org' });
                            variables.push({ caption: 'AWS S3', value: 's3://key:secret@region/bucket/path/to/files -- '+ t('pim.dataport.file.suggest.aws.hint') });

                            variables.push({ caption: t('pim.dataport.file.suggest.soap'), value: 'curl --username:password \n' +
                                    '  --header \'Content-Type: text/xml;charset=UTF-8\' \n' +
                                    '  --header \'SOAPAction: ACTION_YOU_WANT_TO_CALL\' \n' +
                                    '  --data \'<Request XML>\' <SOAP_WEB_SERVICE_ENDPOINT_URL>' });
                            variables.push({ caption: t('pim.dataport.file.suggest.icecat'), value: 'https://<user>:<password>@data.icecat.biz/xml_s3/xml_server3.cgi?ean_upc={{ gtin }};lang=DE;output=productxml' });

                            callback(null, variables);
                        }.bind(this)
                    }];
                    editor.setOptions({
                        showLineNumbers: false,
                        showGutter: false,
                        indentedSoftWrap: false,
                        showPrintMargin: false,
                        wrap: true,
                        //fontFamily: 'Open Sans, Helvetica Neue, helvetica, arial, verdana, sans-serif',
                        fontSize: "13px",
                        enableBasicAutocompletion: true,
                        enableSnippets: true,
                        enableLiveAutocompletion: true,
                        highlightActiveLine: false,
                        maxLines: 100
                    });

                    editor.setValue(fileInput.getValue() || '');
                    editor.clearSelection();

                    editor.on('blur', function () {
                        editor.clearSelection();
                    });
                    editor.on('change', function () {
                        fileInput.setValue(editor.getValue());
                        editorContainer.updateLayout();
                    }.bind(this));

                }.bind(this)
            }
        };
        var editorContainer = Ext.create("Ext.form.field.Display", fieldConfig);

        // Panel für Importkonfiguration
        this.sourceConfigBasePanel = new Ext.form.FormPanel({
            padding: 5,
            layout: {
                type: 'vbox',
                align: 'stretch'
            },
            fieldDefaults: {
                labelAlign: 'left',
                labelWidth: 200
            },
            items: [
                {
                    xtype: 'panel',
                    title: t('settings') + ': ' + this.dataportPanel.getForm().findField('sourcetype').getStore().findRecord("code", this.dataportPanel.getForm().findField('sourcetype').getValue()).get("label"),
                    layout: {
                        type: 'vbox',
                        align: 'stretch'
                    },
                    bodyPadding: '10 0 0 10',
                    items: [
                        fileInput,
                        editorContainer,
                        itemXpathField
                    ]
                },
                {
                    xtype: 'panel',
                    itemId: 'targetSettings',
                    title: t('settings') + ': ' +((this.dataport.itemClass === "0" || this.dataportPanel.getForm().findField('itemClass').getValue() === "0") ? t('export') : t('import')),
                    layout: {
                        type: 'vbox',
                        align: 'stretch'
                    },
                    bodyPadding: '10 0 0 10',
                    items: [
                    {
                        xtype: 'textfield',
                        name: 'itemFolder',
                        hidden: this.dataport.itemClass === "OpenDxp\\Model\\Asset" || this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Asset" || this.dataport.itemClass === "0" || this.dataportPanel.getForm().findField('itemClass').getValue() === "0" || (this.sourceConfigBasePanel && this.sourceConfigBasePanel.getForm().findField('mode').getValue() == '2'),
                        fieldLabel: t('pim.dataport_targetfolder'),
                        cls: "input_drop_target",
                        value: this.dataport.itemFolder,
                        listeners: {
                            render: function (el) {
                                var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                    ddGroup: "element",

                                    getTargetFromEvent: function (e) {
                                        return e.getTarget();
                                    },

                                    onNodeOver: function (target, dd, e, data) {
                                        data = data.records[0].data;

                                        if (data.elementType == "object" || data.elementType == "document") {
                                            return Ext.dd.DropZone.prototype.dropAllowed;
                                        }
                                        return Ext.dd.DropZone.prototype.dropNotAllowed;
                                    },

                                    onNodeDrop: function (target, dd, e, data) {
                                        data = data.records[0].data;

                                        if (data.elementType == "object" || data.elementType == "document") {
                                            target.value = data.path;
                                            return true;
                                        }
                                        return false;
                                    }.bind(this)
                                });

                                el.getEl().on('dblclick', function () {
                                    var elementType = 'object';
                                    if (this.dataport.itemClass === "OpenDxp\\Model\\Asset" || this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Asset") {
                                        elementType = 'asset';
                                    } else if (this.dataport.itemClass === "OpenDxp\\Model\\Document\\Page" || this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Document\\Page") {
                                        elementType = 'document';
                                    }
                                    opendxp.helpers.openElement(el.getValue(), elementType);
                                }.bind(this));
                            }.bind(this)
                        }
                    },
                    {
                        xtype: 'textfield',
                        name: 'masterDocument',
                        hidden: this.dataport.itemClass !== "OpenDxp\\Model\\Document\\Page" || this.dataportPanel.getForm().findField('itemClass').getValue() !== "OpenDxp\\Model\\Document\\Page",
                        fieldLabel: t('content_master_document'),
                        cls: "input_drop_target",
                        value: this.dataport.masterDocument,
                        listeners: {
                            render: function (el) {
                                var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                    ddGroup: "element",

                                    getTargetFromEvent: function (e) {
                                        return this.getEl();
                                    },

                                    onNodeOver: function (target, dd, e, data) {
                                        data = data.records[0].data;

                                        if (data.elementType == "document") {
                                            return Ext.dd.DropZone.prototype.dropAllowed;
                                        }
                                        return Ext.dd.DropZone.prototype.dropNotAllowed;
                                    },

                                    onNodeDrop: function (target, dd, e, data) {
                                        data = data.records[0].data;

                                        if (data.elementType == "document") {
                                            this.setValue(data.path);
                                            return true;
                                        }
                                        return false;
                                    }.bind(this)
                                });

                                this.getEl().on('dblclick', function () {
                                    opendxp.helpers.openElement(this.getValue(), 'document');
                                }.bind(this));
                            }
                        }
                    },
                    {
                        xtype: 'textfield',
                        name: 'assetSource',
                        fieldLabel: t('pim.dataport.csv.assetSource'),
                        cls: "input_drop_target",
                        inputAttrTpl: 'data-qtip="' + t('pim.dataport.folder.pimcore_or_filesystem') + '"',
                        listeners: {
                            render: function (el) {
                                var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                    ddGroup: "element",

                                    getTargetFromEvent: function (e) {
                                        return this.getEl();
                                    },

                                    onNodeOver: function (target, dd, e, data) {
                                        data = data.records[0].data;

                                        if (data.elementType == "asset" && data.type == "folder") {
                                            return Ext.dd.DropZone.prototype.dropAllowed;
                                        }
                                        return Ext.dd.DropZone.prototype.dropNotAllowed;
                                    },

                                    onNodeDrop: function (target, dd, e, data) {
                                        data = data.records[0].data;

                                        if (data.elementType == "asset" && data.type == "folder") {
                                            this.setValue(data.path);
                                            return true;
                                        }
                                        return false;
                                    }.bind(this)
                                });

                                this.getEl().on('dblclick', function () {
                                    opendxp.helpers.openElement(this.getValue(), 'asset');
                                }.bind(this));
                            }
                        },
                        value: this.getSourceconfig('assetSource')
                    },
                    {
                        xtype: 'textfield',
                        name: 'assetFolder',
                        fieldLabel: t('pim.dataport_assetfolder'),
                        inputAttrTpl: 'data-qtip="' + t('pim.dataport.folder.pimcore_or_filesystem') + '"',
                        cls: "input_drop_target",
                        listeners: {
                            render: function (el) {
                                var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                    ddGroup: "element",

                                    getTargetFromEvent: function (e) {
                                        return this.getEl();
                                    },

                                    onNodeOver: function (target, dd, e, data) {
                                        data = data.records[0].data;

                                        if (data.elementType == "asset" && data.type == "folder") {
                                            return Ext.dd.DropZone.prototype.dropAllowed;
                                        }
                                        return Ext.dd.DropZone.prototype.dropNotAllowed;
                                    },

                                    onNodeDrop: function (target, dd, e, data) {
                                        data = data.records[0].data;

                                        if (data.elementType == "asset" && data.type == "folder") {
                                            this.setValue(data.path);
                                            return true;
                                        }
                                        return false;
                                    }.bind(this)
                                });

                                this.getEl().on('dblclick', function () {
                                    opendxp.helpers.openElement(this.getValue(), 'asset');
                                }.bind(this));
                            }
                        },
                        value: this.dataport.assetFolder
                    },
                    {
                        xtype: 'checkbox',
                        name: 'autoImport',
                        fieldLabel: t('pim.dataport.auto-import'),
                        value: this.getSourceconfig('autoImport'),
                        autoEl: {
                            tag: 'div',
                            'data-qtip': (this.dataport.itemClass === "0" || this.dataportPanel.getForm().findField('itemClass').getValue() === "0") ? t('pim.dataport.auto-import.tooltip.export') : t('pim.dataport.auto-import.tooltip')
                        },
                        listeners: {
                            change: function (el, newValue) {
                                if (!newValue) {
                                    this.sourceConfigBasePanel.getForm().findField('incrementalExport').hide();
                                } else if (this.dataportPanel.getForm().findField('itemClass').getValue() === '0') {
                                    this.sourceConfigBasePanel.getForm().findField('incrementalExport').show();
                                }
                            }.bind(this)
                        }
                    },
                    {
                        xtype: 'checkbox',
                        name: 'incrementalExport',
                        fieldLabel: t('pim.dataport.incremental_export'),
                        value: this.getSourceconfig('incrementalExport'),
                        hidden: this.dataport.itemClass !== "0" || this.dataportPanel.getForm().findField('itemClass').getValue() !== "0",
                        autoEl: {
                            tag: 'div',
                            'data-qtip': t('pim.dataport.incremental_export.tooltip')
                        }
                    }, {
                        xtype: 'textfield',
                        name: 'archiveFolder',
                        fieldLabel: t('pim.dataport_archiveFolder'),
                        cls: "input_drop_target",
                        value: this.getSourceconfig('archiveFolder'),
                        listeners: {
                            render: function (el) {
                                var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                    ddGroup: "element",

                                    getTargetFromEvent: function (e) {
                                        return this.getEl();
                                    },

                                    onNodeOver: function (target, dd, e, data) {
                                        data = data.records[0].data;

                                        if (data.elementType == "asset" && data.type == "folder") {
                                            return Ext.dd.DropZone.prototype.dropAllowed;
                                        }
                                        return Ext.dd.DropZone.prototype.dropNotAllowed;
                                    },

                                    onNodeDrop: function (target, dd, e, data) {
                                        data = data.records[0].data;

                                        if (data.elementType == "asset" && data.type == "folder") {
                                            this.setValue(data.path);
                                            return true;
                                        }
                                        return false;
                                    }.bind(this)
                                });

                                this.getEl().on('dblclick', function () {
                                    opendxp.helpers.openElement(this.getValue(), 'asset');
                                }.bind(this));
                            }
                        }
                    }]
                },
                {
                    xtype: 'panel',
                    collapsible: true,
                    collapsed: true,
                    title: t('pim.dataport_advancedOptions'),
                    cls: 'x-panel-header-light',
                    style: 'border: none',
                    bodyPadding: '10 0 0 10',
                    headerOverCls: 'x-fieldset-header-text-collapsible',
                    titleCollapse: true,
                    fieldDefaults: {
                        labelAlign: 'left',
                        labelWidth: 200
                    },
                    layout: {
                        type: 'vbox',
                        align: 'stretch'
                    },
                    items: [
                        {
                            xtype: 'combo',
                            name: 'mode',
                            fieldLabel: t('pim.dataport_mode'),
                            editable: false,
                            forceSelection: true,
                            store: [
                                ['3', t('pim.dataport_mode.create_and_edit')],
                                ['1', t('pim.dataport_mode.create_only')],
                                ['2', t('pim.dataport_mode.edit_only')]
                            ],
                            value: this.dataport.mode
                        },
                        {
                            xtype: 'checkbox',
                            name: 'compatibilityMode',
                            value: this.dataport.compatibilityMode,
                            fieldLabel: t('pim.compatibilityMode'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.compatibilityMode.tooltip')
                            }
                        },
                        {
                            xtype: 'combo',
                            name: 'javascriptEngine',
                            fieldLabel: t('pim.dataport_javascriptEngine'),
                            hidden: true,
                            editable: false,
                            triggerAction: 'all',
                            store: Ext.create('Ext.data.JsonStore', {
                                model: 'JavascriptEngineModel',
                                proxy: {
                                    type: 'ajax',
                                    url: '/admin/SylphenDataBridge/importconfig/get-javascript-engines',
                                    reader: {
                                        type: 'json',
                                        rootProperty: 'data'
                                    }

                                },
                                autoLoad: true
                            }),
                            valueField: 'id',
                            displayField: 'name',
                            value: this.dataport.javascriptEngine,
                            listeners: {
                                select: function (comp, record, index) {
                                    if (comp.getValue() == "" || comp.getValue() == "&nbsp;") {
                                        comp.setValue(null);
                                    }
                                }
                            }
                        },
                        {
                            xtype: 'checkbox',
                            name: 'optimizeInheritance',
                            value: this.dataport.optimizeInheritance,
                            fieldLabel: t('pim.dataport_optimize_inheritance'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport_optimize_inheritance.tooltip')
                            }
                        },
                        {
                            xtype: 'numberfield',
                            minValue: 0,
                            decimalPrecision: 0,
                            name: 'parallelProcesses',
                            value: this.dataport.parallelProcesses,
                            fieldLabel: t('pim.dataport_parallel_processes'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport_parallel_processes.tooltip')
                            }
                        },
                        {
                            xtype: 'checkbox',
                            name: 'skipVersioning',
                            fieldLabel: t('pim.dataport_skip_versioning'),
                            value: this.dataport.skipVersioning,
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport_skip_versioning.tooltip')
                            },
                            hidden: true
                        }, {
                            xtype: 'textfield',
                            name: 'idPrefix',
                            value: this.dataport.idPrefix,
                            fieldLabel: t('pim.dataport_idprefix'),
                            allowBlank: true,
                            hidden: true
                        }, {
                            xtype: 'textfield',
                            name: 'categoryClass',
                            value: this.dataport.categoryClass,
                            fieldLabel: t('pim.dataport_categoryclass'),
                            allowBlank: true,
                            hidden: true
                        }, {
                            xtype: 'textfield',
                            name: 'fieldnameProducts',
                            value: this.dataport.fieldnameProducts,
                            fieldLabel: t('pim.dataport_fieldnameProducts'),
                            allowBlank: true,
                            hidden: true
                        },
                        {
                            xtype: 'tagfield',
                            name: 'error_recipients[]',
                            fieldLabel: t('pim.dataport.send_error_notification'),
                            store: Ext.create('Ext.data.JsonStore', {
                                proxy: {
                                    type: 'ajax',
                                    url: '/admin/SylphenDataBridge/importconfig/get-recipients/' + this.dataport.id,
                                    fields: ['name', 'userId'],
                                    reader: {
                                        type: 'json',
                                        rootProperty: 'data'
                                    }

                                },
                                autoLoad: true
                            }),
                            value: this.dataport.errorRecipients,
                            valueField: 'userId',
                            displayField: 'name',
                            filterPickList: true,
                            forceSelection: true,
                            queryMode: 'local',
                            anyMatch: true
                        }
                    ]
                }
              ]
        });

        //this.sourceConfigPanel.add(this.sourceConfigRawitemPanel);
        this.sourceConfigPanel.add(this.sourceConfigBasePanel, this.sourceConfigRawitemPanel);

        opendxp.layout.refresh();
    },


    renderCsvSourceConfigPanel: function () {
        var additionalColumns = this.getColumnConfigForSourcetype('csv');

        // Rohdatenfeldkonfiguration
        if (typeof CsvImportconfigModel == 'undefined') {
            var readerFields = [
                {name: 'fieldNo', allowBlank: false, type: 'integer'},
                { name: 'sort', allowBlank: false },
                {name: 'name', allowBlank: false}
            ];

            Ext.each(additionalColumns, function (column) {
                readerFields.push({name: column.dataIndex});
            });

            readerFields.push({name: 'data1', persist: false});

            Ext.define('CsvImportconfigModel', {
                    extend: 'Ext.data.Model',
                    fields: readerFields,
                    idProperty: 'fieldNo',
                    root: 'fields'
                }
            );
        }

        var store = new Ext.data.JsonStore({
            model: 'CsvImportconfigModel',
            proxy: {
                type: 'ajax',
                url: '/admin/SylphenDataBridge/importconfig/get-rawitemfield-config/' + this.dataport.id,
                reader: {
                    type: 'json',
                    rootProperty: 'fields',
                    messageProperty: 'errorMessage'
                }
            },
            listeners: {
              load: function () {
                  Ext.Ajax.request({
                      url: '/admin/SylphenDataBridge/importconfig/get-demo-data',
                      params: {
                          dataportId: this.dataportId
                      },
                      success: function (response) {
                          response = Ext.decode(response.responseText);

                          var store = this.sourceConfigRawitemPanel.getStore();
                          Ext.each(response.data, function (value) {
                              var record = store.findRecord('fieldNo', value.fieldNo, 0, false, true, true)

                              if (record) {
                                  record.set('data1', value.value);
                              }
                          }.bind(this));

                          this.sourceConfigRawitemPanel.getView().refresh();
                      }.bind(this)
                  });
              }.bind(this)
            },
            autoLoad: true
        });


        var columnConfig = [];
        columnConfig.push({
            header: "#",
            width: 50,
            dataIndex: 'fieldNo',
            renderer: function (value, metaData, record) {
                if (record.get('sort') === 'DESC') {
                    metaData.tdAttr = 'data-qtip="' + t('pim.dataport-fields.sorting-direction.desc') + '"';
                    value += ' <img src="/bundles/opendxpadmin/img/flat-color-icons/alphabetical_sorting_za.svg" height="18" width="18" style="height:18px;vertical-align:middle">';
                } else {
                    metaData.tdAttr = 'data-qtip="' + t('pim.dataport-fields.sorting-direction.asc') + '"';
                }
                return value;
            }
        });
        columnConfig.push({
            header: t('pim.dataport-fields-name'),
            dataIndex: 'name',
            flex: 1,
            width: 'auto',
            renderer: function (value, metaData, record) {
                if (!value) {
                    return '';
                }

                var mappingRecords = this.mappingPanel.getStore().queryBy(function () { return true; }).getRange();
                var isMapped = false;
                var minOneFieldIsMapped = false;
                Ext.each(mappingRecords, function (mappingRecord) {
                    if (mappingRecord.get('field')) {
                        minOneFieldIsMapped = true;
                    }

                    if (record.get('fieldNo') === mappingRecord.get('field')) {
                        isMapped = true;
                        return false;
                    }

                    var mappingRecordSettings = mappingRecord.get('settings');
                    if (mappingRecordSettings.calculation) {
                        minOneFieldIsMapped = true;
                        if (this.dataport.javascriptEngine === 'php' && (mappingRecordSettings.calculation.indexOf('$params[\'rawItemData\'][\'' + value + '\']') > -1 || mappingRecordSettings.calculation.indexOf('$params[\'rawItemData\'][\'field_' + record.get('fieldNo') + '\']') > -1)) {
                            isMapped = true;
                            return false;
                        } else if (this.dataport.javascriptEngine === 'v8js' && (mappingRecordSettings.calculation.indexOf('params[\'rawItemData\'][\'' + value + '\']') > -1 || mappingRecordSettings.calculation.indexOf('params.rawItemData.' + value) > -1)) {
                            isMapped = true;
                            return false;
                        }
                    }
                }.bind(this));

                if (!isMapped && minOneFieldIsMapped) {
                    metaData.tdAttr = 'data-qtip="' + t('pim.mapping.variables.variable.not_in_use') + '"';
                    return '<s>' + Ext.util.Format.htmlEncode(value) + '</s>';
                }
                return Ext.util.Format.htmlEncode(value);
            }.bind(this),
            editor: {
                xtype: 'textfield',
                allowBlank: false,
                handleMouseEvents: true,
                listeners: {
                    render: function (cmp) {
                        Ext.get(cmp.getInputId()).on('mousedown', function (e) {
                            e.stopPropagation();
                        });
                    }
                }
            }
        });

        Ext.each(additionalColumns, function (column) {
            columnConfig.push(column);
        });

        columnConfig.push({
            header: t('pim.dataport-fields-ex1'),
            dataIndex: 'data1',
            flex: 1,
            renderer: function (value, metaData, record) {
                if (typeof value === 'undefined') {
                    return '';
                }
                return Ext.util.Format.htmlEncode(value);
            }
        });

        var autoCreate = function (response) {
            response = Ext.decode(response.responseText);

            if (typeof response.config !== 'undefined' && response.config.fields.length > 0) {
                if (response.config.separator) {
                    this.sourceConfigBasePanel.getForm().findField('separator').setValue(response.config.separator);
                }

                var store = this.sourceConfigRawitemPanel.getStore();
                Ext.each(response.config.fields, function (field) {
                    if (store.findExact('name', field.name) === -1 && store.findExact('column', field.name) === -1) {
                        this.onAddDataport();

                        var record = this.sourceConfigRawitemPanel.getSelectionModel().getSelection()[0];
                        record.set({
                            name: field.name,
                            column: field.column
                        });
                    }
                }.bind(this));
            } else {
                var uploadWindow = Ext.create('Ext.Window', {
                    layout: {
                        type: 'vbox',
                        align: 'stretch'
                    },
                    title: t('pim.dataport.auto_create.window.title'),
                    width: 500,
                    height: 300,
                    modal: true,
                    items: Ext.create('Ext.form.Panel', {
                        padding: 15,
                        itemId: 'uploadForm',
                        layout: {
                            type: 'vbox',
                            align: 'stretch'
                        },
                        items: [{
                            xtype: 'fileuploadfield',
                            allowBlank: false,
                            fieldLabel: t('pim.manual.importForm.uploadLabel'),
                            name: 'file',
                            buttonText: t('select_a_file'),
                            buttonCfg: {
                                iconCls: 'opendxp_icon_file'
                            },
                        }, {
                            xtype: 'hidden',
                            name: 'sourceType',
                            value: this.dataportPanel.getForm().findField('sourcetype').getValue(),
                        }, {
                            xtype: 'hidden',
                            name: 'hasHeader',
                            value: this.sourceConfigBasePanel.getForm().findField('hasHeader').getValue() ? 1 : 0,
                        }, {
                            xtype: 'hidden',
                            name: 'separator',
                            value: this.sourceConfigBasePanel.getForm().findField('separator').getValue(),
                        }, {
                            xtype: 'hidden',
                            name: 'quote',
                            value: this.sourceConfigBasePanel.getForm().findField('quote').getValue(),
                        }, {
                            xtype: 'hidden',
                            name: 'dataportId',
                            value: this.dataportId,
                        }, {
                            xtype: 'hidden',
                            name: 'csrfToken',
                            value: opendxp.settings['csrfToken'],
                        }]
                    }),
                    buttons: [{
                        text: t('pim.dataport.auto_create.window.button'),
                        handler: function () {
                            if (uploadWindow.queryById('uploadForm').getForm().isValid()) {
                                uploadWindow.queryById('uploadForm').getForm().submit({
                                    url: '/admin/SylphenDataBridge/importconfig/auto-create-rawdata-fields',
                                    waitMsg: t('pim.manual.importForm.waitMsg'),
                                    success: function (fp, response) {
                                        if (uploadWindow) {
                                            uploadWindow.close();
                                        }

                                        autoCreate(response.response);
                                    }.bind(this),
                                    failure: function (form, action) {
                                        if (action.result.msg) {
                                            Ext.MessageBox.alert(t('error'), t('pim.manual.importForm.failure') + action.result.msg);
                                        }
                                    }.bind(this)
                                });
                            }
                        }.bind(this)
                    }]
                });
                uploadWindow.show();
            }
        }.bind(this);

        this.sourceConfigRawitemPanel = Ext.create('Ext.grid.Panel', {
            title: '',
            store: store,
            columns: {
                defaults: {
                    menuDisabled : true,
                    sortable: false
                },
                items: columnConfig
            },
            flex: 1,
            border: false,
            frame: false,
            autoScroll: true,
            layout: 'fit',
            minHeight:300,
            selModel: {
                selType: 'rowmodel',
                mode: 'MULTI',
                ignoreRightMouseSelection: true
            },
            multiSelect: true,
            dockedItems: [{
                xtype: 'toolbar',
                dock: 'top',
                items: [
                {
                    xtype: 'label',
                    html: t('pim.rawdatafields'),
                    cls: 'x-panel-header-title-default x-panel-header-default',
                    style: 'border:none;'
                },
                {
                    text: t('add'),
                    handler: this.onAddDataport.bind(this),
                    iconCls: "opendxp_icon_add"
                },
                '-',
                {
                    text: t('delete'),
                    handler: function () {
                        this.onDeleteDataport();
                    }.bind(this),
                    iconCls: "opendxp_icon_delete"
                },
                '-',
                {
                    text: t('pim.rawdatafields.bulk_edit'),
                    iconCls: "opendxp_icon_edit",
                    handler: function () {
                        var displayField = {
                            xtype: "displayfield",
                            region: "north",
                            hideLabel: true,
                            border: false,
                            value: t('pim.rawdatafields.bulk_edit.description') + ':<br>' + Ext.Array.filter(Ext.Array.map(columnConfig, function (column) {
                                return column.dataIndex;
                            }), function (dataIndex) {
                                return dataIndex !== 'data1';
                            }).join(',')
                        };

                        var data = [];
                        store.each(function (rec) {
                            var row = [];
                            Ext.Array.each(columnConfig, function (column) {
                                if (column.dataIndex === 'data1') {
                                    return true;
                                }
                                row.push(rec.get(column.dataIndex));
                            });
                            data.push(row);
                        });

                        data = Ext.util.CSV.encode(data);

                        var textarea = new Ext.form.TextArea({
                            region: "center",
                            border: false,
                            value: data
                        });

                        var bulkEditWindow = new Ext.Window({
                            width: 800,
                            height: 500,
                            title: t('pim.rawdatafields.bulk_edit'),
                            iconCls: "opendxp_icon_edit",
                            layout: "fit",
                            modal: true,
                            resizable: true,
                            items: [new Ext.Panel({
                                layout: "border",
                                padding: '0 10',
                                items: [displayField, textarea]
                            })],
                            buttons: [
                                {
                                    text: t('apply'),
                                    iconCls: "opendxp_icon_save",
                                    handler: function () {
                                        store.removeAll();
                                        var content = textarea.getValue();
                                        if (content.length > 0) {
                                            var csvData = Ext.util.CSV.decode(content);

                                            for (var i = 0; i < csvData.length; i++) {
                                                var row = csvData[i];

                                                this.onAddDataport();
                                                var record = this.sourceConfigRawitemPanel.getSelectionModel().getSelection()[0];

                                                Ext.Array.each(columnConfig, function (column, index) {
                                                    record.set(column.dataIndex, row[index])
                                                });
                                            }
                                        }

                                        bulkEditWindow.close();
                                    }.bind(this)
                                },
                                {
                                    text: t('cancel'),
                                    iconCls: "opendxp_icon_empty",
                                    handler: function () {
                                        bulkEditWindow.close();
                                    }
                                }
                            ]
                        }).show();
                    }.bind(this)
                },
                '->',
                {
                    text: t('pim.dataport.auto_create'),
                    handler: function() {
                        Ext.Ajax.request({
                            url: '/admin/SylphenDataBridge/importconfig/auto-create-rawdata-fields',
                            method: 'post',
                            params: {
                                sourceType: this.dataportPanel.getForm().findField('sourcetype').getValue(),
                                file: this.sourceConfigBasePanel.getForm().findField('file').getValue(),
                                hasHeader: this.sourceConfigBasePanel.getForm().findField('hasHeader').getValue()?1:0,
                                separator:  this.sourceConfigBasePanel.getForm().findField('separator').getValue(),
                                quote:  this.sourceConfigBasePanel.getForm().findField('quote').getValue(),
                                dataportId: this.dataportId
                            },
                            success: autoCreate
                        });
                    }.bind(this),
                    iconCls: "opendxp_icon_clear_cache"
                }]
            }],

            listeners: {
                beforeedit: function(editor, context) {
                    context.grid.getView().getPlugin('gridviewdragdrop').disable();
                },
                canceledit: function(editor, context) {
                    context.grid.getView().getPlugin('gridviewdragdrop').enable();
                },
                edit: function (editor, e) {
                    if (!e.record.get('name') && e.field === 'column' && e.value !== '') {
                        var isValid = true;

                        store.each(function (record) {
                            if (record.get('name') === e.value && record.get('fieldNo') !== e.record.get('fieldNo')) {
                                isValid = false;
                                return false;
                            }
                        });

                        if (isValid) {
                            e.record.set('name', e.value);
                        }
                    }

                    this.onAddDataport(false);

                    e.grid.getView().getPlugin('gridviewdragdrop').enable();
                }.bind(this),
                validateedit: function (editor, e) {
                    var isValid = true;

                    store.each(function (record) {
                        if (e.value !== '' && record.get(e.field) === e.value && e.record.get('fieldNo') !== record.get('fieldNo')) {
                            isValid = false;
                            return false;
                        }
                    });

                    if (!isValid) {
                        opendxp.helpers.showNotification(t("error"), t('pim.dataport-fields.duplicate'), "error");
                    }

                    return isValid;
                }.bind(this),
                celldblclick: function (grid, cell, cellIndex, record) {
                    if (columnConfig[cellIndex].dataIndex === 'data1') {
                        opendxp.helpers.copyStringToClipboard(record.get('data1'));
                        opendxp.helpers.showNotification(t("info"), t('pim.mapping.copied_to_clipboard_mapping'), 'info');
                    }
                },
                cellclick: function (grid, tdElement, cellIndex, record) {
                    if (columnConfig[cellIndex].dataIndex === 'fieldNo') {
                        if (record.get('sort') === 'ASC') {
                            record.set('sort', 'DESC');
                        } else {
                            record.set('sort', 'ASC');
                        }
                    }
                },
                itemcontextmenu: function(view, record, item, index, e) {
                    var menu = new Ext.menu.Menu();

                    var selectedRows = this.sourceConfigRawitemPanel.getSelectionModel().getSelection();
                    var clickedItemIsSelected = false;
                    if(selectedRows.length > 0) {
                        Ext.each(selectedRows, function (selectedRecord, index) {
                            if (record.get('fieldNo') === selectedRecord.get('fieldNo')) {
                                clickedItemIsSelected = true;
                                return false;
                            }
                        });
                    } else {
                        clickedItemIsSelected = true;
                    }

                    var records = [];
                    if (clickedItemIsSelected) {
                        records = selectedRows.reverse();
                    }

                    if (records.length === 0) {
                        records = selectedRows.length > 0 ? selectedRows : [record];
                    }

                    menu.add(new Ext.menu.Item({
                        text: t('pim.dataport.move-to-top'),
                        icon: "/bundles/opendxpadmin/img/flat-color-icons/up.svg",
                        handler: function(menuItem, e) {
                            Ext.each(records, function (record) {
                                store.data.remove(record);
                                store.data.insert(0, record);
                            });
                        }.bind(this),
                    }));

                    if(!clickedItemIsSelected) {
                        menu.add(new Ext.menu.Item({
                            text: t('pim.dataport.move-to-selection').replace('%s', selectedRows[selectedRows.length - 1].get('name')),
                            iconCls: "opendxp_icon_table_row",
                            handler: function (menuItem, e) {
                                store.data.remove(record);
                                store.data.insert(store.indexOf(selectedRows[selectedRows.length - 1]) + 1, record);
                            }.bind(this),
                        }));
                    }

                    if (record.get('sort') === null || record.get('sort') === 'ASC') {
                        menu.add(new Ext.menu.Item({
                            text: t('pim.dataport.sort-descending'),
                            icon: "/bundles/opendxpadmin/img/flat-color-icons/alphabetical_sorting_za.svg",
                            handler: function (menuItem, e) {
                                Ext.each(records, function (record) {
                                    record.set('sort', 'DESC');
                                });
                            }.bind(this),
                        }));
                    } else if (record.get('sort') === 'DESC') {
                        menu.add(new Ext.menu.Item({
                            text: t('pim.dataport.sort-ascending'),
                            icon: "/bundles/opendxpadmin/img/flat-color-icons/alphabetical_sorting_az.svg",
                            handler: function (menuItem, e) {
                                Ext.each(records, function (record) {
                                    record.set('sort', 'ASC');
                                });
                            }.bind(this),
                        }));
                    }

                    menu.add(new Ext.menu.Item({
                        text: t('add'),
                        iconCls: "opendxp_icon_add",
                        handler: this.onAddDataport.bind(this),
                    }));

                    menu.add(new Ext.menu.Item({
                        text: t('copy'),
                        iconCls: "opendxp_icon_copy",
                        handler: function() {
                            var selectedRows = this.sourceConfigRawitemPanel.getSelectionModel().getSelection();
                            Ext.each(records, function (record) {
                                if (selectedRows.length === 1) {
                                    this.sourceConfigRawitemPanel.getSelectionModel().select(record);
                                } else {
                                    this.sourceConfigRawitemPanel.getSelectionModel().deselectAll();
                                }

                                var addedRecord = this.onAddDataport();
                                var name = record.get('name').replace(/(.*)(\d+)$/, function (input, namePrefix, trailingDigits) {
                                    return namePrefix + (parseInt(trailingDigits, 10) + 1);
                                });

                                addedRecord.set('name', name);

                                var column = record.get('column');
                                addedRecord.set('column', column);
                            }.bind(this));
                        }.bind(this)
                    }));

                    menu.add(new Ext.menu.Item({
                        text: t('delete'),
                        iconCls: "opendxp_icon_delete",
                        handler: function () {
                            this.onDeleteDataport(record);
                        }.bind(this)
                    }));

                    menu.showAt(e.getXY());

                    e.stopEvent();
                }.bind(this)
            },
            plugins: {
                ptype: 'cellediting',
                clicksToEdit: 2
            },
            viewConfig: {
                plugins: {
                    ptype: 'gridviewdragdrop',
                    pluginId: 'gridviewdragdrop',
                    dragText: t('pim.dataport.change-order-hint')
                }
            }
        });

        var fileInput = Ext.create('Ext.form.field.TextArea', {
            name: 'file',
            fieldLabel: t('pim.dataport.file'),
            value: this.getSourceconfig('file'),
            hidden: true,
            fieldStyle: 'background: url(/bundles/opendxpadmin/img/flat-color-icons/target.svg) right 5px center/20px no-repeat transparent !important;'
        });

        var editorId = Ext.id();
        var editor;
        var fieldConfig = {
            fieldLabel: t('pim.dataport.file'),
            name: 'fileEditor',
            value: '<div id="' + editorId + '" style="height:14px;width:100%;line-height:21px;background: url(/bundles/opendxpadmin/img/flat-color-icons/target.svg) right 5px center/20px no-repeat transparent !important;"></div>',
            fieldCls: 'x-form-text-default x-form-trigger-wrap-default',
            fieldStyle: 'padding-right: 0',
            style: 'opacity: 1',
            listeners: {
                render: function (el) {
                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                        ddGroup: "element",

                        getTargetFromEvent: function (e) {
                            return this.getEl();
                        },

                        onNodeOver: function (target, dd, e, data) {
                            data = data.records[0].data;

                            if (data.elementType == "asset") {
                                return Ext.dd.DropZone.prototype.dropAllowed;
                            }
                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                        },

                        onNodeDrop: function (target, dd, e, data) {
                            data = data.records[0].data;

                            if (data.elementType == "asset") {
                                editor.setValue(data.path);
                                return true;
                            }
                            return false;
                        }.bind(this)
                    });

                    this.getEl().on('dblclick', function () {
                        if(editor.getValue().length < 512) {
                            Ext.Ajax.request({
                                url: '/admin/element/get-subtype',
                                params: {
                                    id: editor.getValue(),
                                    type: 'asset'
                                },
                                success: function (response) {
                                    var res = Ext.decode(response.responseText);
                                    if (res.success) {
                                        opendxp.helpers.openElement(res.id, res.type, res.subtype);
                                    }
                                }
                            });
                        }
                    }.bind(this));
                },
                afterrender: function (cmp) {
                    editor = ace.edit(editorId);
                    editor.setTheme('ace/theme/chrome');
                    if (fileInput.getValue().indexOf("{{") === -1 && fileInput.getValue().indexOf("{%") === -1 && fileInput.getValue().indexOf("\n") > -1) {
                        editor.session.setMode('ace/mode/sh');
                    } else {
                        editor.session.setMode('ace/mode/twig');
                    }

                    var languageTools = ace.require("ace/ext/language_tools");
                    editor.completers = [languageTools.snippetCompleter, languageTools.textCompleter, {
                        getCompletions: function (editor, session, pos, prefix, callback) {
                            var variables = [];

                            var mappingRecords = this.mappingPanel.getStore().queryBy(function () { return true; }).getRange();
                            Ext.each(mappingRecords, function (mappingRecord) {
                                if(['__result_callback', '__result_action', '__init_action'].indexOf(mappingRecord.get('attributeKey')) > -1 || mappingRecord.get('attributeKey').indexOf('__virtual_') > -1) {
                                    return true;
                                }
                                var name = '';
                                if(mappingRecord.get('attributeKey').toLowerCase() !== mappingRecord.get('attributeName').toLowerCase()) {
                                    name = ' -> '+ mappingRecord.get('attributeName');
                                }
                                variables.push({ caption: '{{ '+ mappingRecord.get('attributeKey')+' }}'+name, value: mappingRecord.get('attributeKey') +' }}' });
                            });

                            variables.push({ caption: 'SFTP', value: 'sftp://user:password@hostname/path/to/files' });
                            variables.push({ caption: 'FTP', value: 'ftp://user:password@hostname/path/to/files' });
                            variables.push({ caption: 'FTPS', value: 'ftps://user:password@hostname/path/to/files' });
                            variables.push({ caption: t('pim.dataport.file.suggest.http_basic_auth'), value: 'https://user:password@example.org/import-file' });
                            variables.push({
                                caption: t('pim.dataport.file.suggest.http_post'), value: 'curl -X POST\n' +
                                    '  --header \'Content-Type: application/json\'\n' +
                                    '  --data \'{ "param1": "value1", "param2":"value2"}\' \n' +
                                    '  https://example.org'
                            });
                            variables.push({ caption: 'AWS S3', value: 's3://key:secret@region/bucket/path/to/files -- '+ t('pim.dataport.file.suggest.aws.hint') });

                            callback(null, variables);
                        }.bind(this)
                    }];
                    editor.setOptions({
                        showLineNumbers: false,
                        showGutter: false,
                        indentedSoftWrap: false,
                        showPrintMargin: false,
                        wrap: true,
                        //fontFamily: 'Open Sans, Helvetica Neue, helvetica, arial, verdana, sans-serif',
                        fontSize: "13px",
                        enableBasicAutocompletion: true,
                        enableSnippets: true,
                        enableLiveAutocompletion: true,
                        highlightActiveLine: false,
                        maxLines: 100
                    });

                    editor.setValue(fileInput.getValue() || '');
                    editor.clearSelection();

                    editor.on('blur', function() {
                        editor.clearSelection();
                    });
                    editor.on('change', function () {
                        fileInput.setValue(editor.getValue());
                        editorContainer.updateLayout();
                    }.bind(this));

                }.bind(this)
            }
        };
        var editorContainer = Ext.create("Ext.form.field.Display", fieldConfig);

        // Panel für Importkonfiguration
        this.sourceConfigBasePanel = new Ext.form.FormPanel({
            padding: 5,
            layout: {
                type: 'vbox',
                align: 'stretch'
            },
            fieldDefaults: {
                labelAlign: 'left',
                labelWidth: 200
            },
            items: [
                {
                    xtype: 'panel',
                    title: t('settings') + ': ' + this.dataportPanel.getForm().findField('sourcetype').getStore().findRecord("code", this.dataportPanel.getForm().findField('sourcetype').getValue()).get("label"),
                    layout: {
                        type: 'vbox',
                        align: 'stretch'
                    },
                    bodyPadding: '10 0 0 10',
                    items: [
                        fileInput,
                        editorContainer,
                        {
                            xtype: 'checkbox',
                            name: 'hasHeader',
                            fieldLabel: t('pim.dataport.csv.hasHeader'),
                            value: this.getSourceconfig('hasHeader') !== null ? this.getSourceconfig('hasHeader') : true
                        },
                        Ext.create('Ext.panel.Panel', {
                            layout: {
                                type: 'hbox',
                                align: 'stretch'
                            },
                            items: [
                                {
                                    xtype: 'textfield',
                                    name: 'separator',
                                    fieldLabel: t('pim.dataport.csv.separator'),
                                    value: this.getSourceconfig('separator') || ';',
                                    allowBlank: false,
                                    flex:1
                                }, {
                                    xtype: 'textfield',
                                    name: 'quote',
                                    labelAlign:'right',
                                    fieldLabel: t('pim.dataport.csv.quote'),
                                    value: this.getSourceconfig('quote') || '"',
                                    allowBlank: false,
                                    flex: 1
                                }
                            ]
                        }),
                    ]
                },
                {
                    xtype: 'panel',
                    itemId: 'targetSettings',
                    title: t('settings') + ': ' +((this.dataport.itemClass === "0" || this.dataportPanel.getForm().findField('itemClass').getValue() === "0") ? t('export') : t('import')),
                    layout: {
                        type: 'vbox',
                        align: 'stretch'
                    },
                    bodyPadding: '10 0 0 10',
                    items: [
                        {
                            xtype: 'textfield',
                            name: 'itemFolder',
                            hidden: this.dataport.itemClass === "OpenDxp\\Model\\Asset" || this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Asset" || this.dataport.itemClass === "0" || this.dataportPanel.getForm().findField('itemClass').getValue() === "0" || (this.sourceConfigBasePanel && this.sourceConfigBasePanel.getForm().findField('mode').getValue() == '2'),
                            fieldLabel: t('pim.dataport_targetfolder'),
                            cls: "input_drop_target",
                            inputAttrTpl: 'data-qtip="' + t('pim.dataport.folder.pimcore_or_filesystem') + '"',
                            value: this.dataport.itemFolder,
                            listeners: {
                                render: function (el) {
                                    var me = this;
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return e.getTarget();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType === "object" || data.elementType === "document") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType === "object" || data.elementType === "document") {
                                                target.value = data.path;
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    el.getEl().on('dblclick', function () {
                                        var elementType = 'object';
                                        if (this.dataport.itemClass === "OpenDxp\\Model\\Asset" || this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Asset") {
                                            elementType = 'asset';
                                        } else if (this.dataport.itemClass === "OpenDxp\\Model\\Document\\Page" || this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Document\\Page") {
                                            elementType = 'document';
                                        }
                                        opendxp.helpers.openElement(el.getValue(), elementType);
                                    }.bind(this));
                                }.bind(this)
                            }
                        },
                        {
                            xtype: 'textfield',
                            name: 'masterDocument',
                            hidden: this.dataport.itemClass !== "OpenDxp\\Model\\Document\\Page" || this.dataportPanel.getForm().findField('itemClass').getValue() !== "OpenDxp\\Model\\Document\\Page",
                            fieldLabel: t('content_master_document'),
                            cls: "input_drop_target",
                            value: this.dataport.masterDocument,
                            listeners: {
                                render: function (el) {
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return this.getEl();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "document") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "document") {
                                                this.setValue(data.path);
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    this.getEl().on('dblclick', function () {
                                        opendxp.helpers.openElement(this.getValue(), 'document');
                                    }.bind(this));
                                }
                            }
                        },
                        {
                            xtype: 'textfield',
                            name: 'assetSource',
                            fieldLabel: t('pim.dataport.csv.assetSource'),
                            cls: "input_drop_target",
                            listeners: {
                                render: function (el) {
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return this.getEl();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                this.setValue(data.path);
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    this.getEl().on('dblclick', function () {
                                        opendxp.helpers.openElement(this.getValue(), 'asset');
                                    }.bind(this));
                                }
                            },
                            value: this.getSourceconfig('assetSource')
                        },
                        {
                            xtype: 'textfield',
                            name: 'assetFolder',
                            fieldLabel: t('pim.dataport_assetfolder'),
                            cls: "input_drop_target",
                            inputAttrTpl: 'data-qtip="' + t('pim.dataport.folder.pimcore_or_filesystem') + '"',
                            listeners: {
                                render: function (el) {
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return this.getEl();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                this.setValue(data.path);
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    this.getEl().on('dblclick', function () {
                                        opendxp.helpers.openElement(this.getValue(), 'asset');
                                    }.bind(this));
                                }
                            },
                            value: this.dataport.assetFolder
                        },
                        {
                            xtype: 'checkbox',
                            name: 'autoImport',
                            fieldLabel: t('pim.dataport.auto-import'),
                            value: this.getSourceconfig('autoImport'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': (this.dataport.itemClass === "0" || this.dataportPanel.getForm().findField('itemClass').getValue() === "0") ? t('pim.dataport.auto-import.tooltip.export') : t('pim.dataport.auto-import.tooltip')
                            },
                            listeners: {
                                change: function (el, newValue) {
                                    if (!newValue) {
                                        this.sourceConfigBasePanel.getForm().findField('incrementalExport').hide();
                                    } else if (this.dataportPanel.getForm().findField('itemClass').getValue() === '0') {
                                        this.sourceConfigBasePanel.getForm().findField('incrementalExport').show();
                                    }
                                }.bind(this)
                            }
                        },
                        {
                            xtype: 'checkbox',
                            name: 'incrementalExport',
                            fieldLabel: t('pim.dataport.incremental_export'),
                            value: this.getSourceconfig('incrementalExport'),
                            hidden: this.dataport.itemClass !== "0" || this.dataportPanel.getForm().findField('itemClass').getValue() !== "0",
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport.incremental_export.tooltip')
                            }
                        },
                        {
                            xtype: 'textfield',
                            name: 'archiveFolder',
                            fieldLabel: t('pim.dataport_archiveFolder'),
                            cls: "input_drop_target",
                            value: this.getSourceconfig('archiveFolder'),
                            listeners: {
                                render: function (el) {
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return this.getEl();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                this.setValue(data.path);
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    this.getEl().on('dblclick', function () {
                                        opendxp.helpers.openElement(this.getValue(), 'asset');
                                    }.bind(this));
                                }
                            }
                        }
                    ]
                },
                {
                    xtype: 'panel',
                    collapsible: true,
                    collapsed: true,
                    title: t('pim.dataport_advancedOptions'),
                    cls: 'x-panel-header-light',
                    style: 'border: none',
                    bodyPadding: '10 0 0 10',
                    headerOverCls: 'x-fieldset-header-text-collapsible',
                    titleCollapse: true,
                    fieldDefaults: {
                        labelAlign: 'left',
                        labelWidth: 200
                    },
                    layout: {
                        type: 'vbox',
                        align: 'stretch'
                    },
                    items: [
                        {
                            xtype: 'combo',
                            name: 'mode',
                            fieldLabel: t('pim.dataport_mode'),
                            editable: false,
                            forceSelection: true,
                            store: [
                                ['3', t('pim.dataport_mode.create_and_edit')],
                                ['1', t('pim.dataport_mode.create_only')],
                                ['2', t('pim.dataport_mode.edit_only')]
                            ],
                            value: this.dataport.mode
                        },
                        {
                            xtype: 'checkbox',
                            name: 'compatibilityMode',
                            value: this.dataport.compatibilityMode,
                            fieldLabel: t('pim.compatibilityMode'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.compatibilityMode.tooltip')
                            }
                        },
                        {
                            xtype: 'combo',
                            name: 'javascriptEngine',
                            fieldLabel: t('pim.dataport_javascriptEngine'),
                            hidden: true,
                            editable: false,
                            triggerAction: 'all',
                            store: Ext.create('Ext.data.JsonStore', {
                                model: 'JavascriptEngineModel',
                                proxy: {
                                    type: 'ajax',
                                    url: '/admin/SylphenDataBridge/importconfig/get-javascript-engines',
                                    reader: {
                                        type: 'json',
                                        rootProperty: 'data'
                                    }

                                },
                                autoLoad: true
                            }),
                            valueField: 'id',
                            displayField: 'name',
                            value: this.dataport.javascriptEngine,
                            listeners: {
                                select: function (comp, record, index) {
                                    if (comp.getValue() == "" || comp.getValue() == "&nbsp;") {
                                        comp.setValue(null);
                                    }
                                }
                            }
                        },
                        {
                            xtype: 'checkbox',
                            name: 'optimizeInheritance',
                            value: this.dataport.optimizeInheritance,
                            fieldLabel: t('pim.dataport_optimize_inheritance'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport_optimize_inheritance.tooltip')
                            }
                        },
                        {
                            xtype: 'numberfield',
                            minValue: 0,
                            decimalPrecision: 0,
                            name: 'parallelProcesses',
                            value: this.dataport.parallelProcesses,
                            fieldLabel: t('pim.dataport_parallel_processes'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport_parallel_processes.tooltip')
                            }
                        },
                        {
                            xtype: 'checkbox',
                            name: 'skipVersioning',
                            fieldLabel: t('pim.dataport_skip_versioning'),
                            value: this.dataport.skipVersioning,
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport_skip_versioning.tooltip')
                            },
                            hidden: true
                        }, {
                            xtype: 'textfield',
                            name: 'idPrefix',
                            value: this.dataport.idPrefix,
                            fieldLabel: t('pim.dataport_idprefix'),
                            allowBlank: true,
                            hidden: true
                        }, {
                            xtype: 'textfield',
                            name: 'categoryClass',
                            value: this.dataport.categoryClass,
                            fieldLabel: t('pim.dataport_categoryclass'),
                            allowBlank: true,
                            hidden: true
                        }, {
                            xtype: 'textfield',
                            name: 'fieldnameProducts',
                            value: this.dataport.fieldnameProducts,
                            fieldLabel: t('pim.dataport_fieldnameProducts'),
                            allowBlank: true,
                            hidden: true
                        },
                        {
                            xtype: 'tagfield',
                            name: 'error_recipients[]',
                            fieldLabel: t('pim.dataport.send_error_notification'),
                            store: Ext.create('Ext.data.JsonStore', {
                                proxy: {
                                    type: 'ajax',
                                    url: '/admin/SylphenDataBridge/importconfig/get-recipients/' + this.dataport.id,
                                    fields: ['name', 'userId'],
                                    reader: {
                                        type: 'json',
                                        rootProperty: 'data'
                                    }

                                },
                                autoLoad: true
                            }),
                            value: this.dataport.errorRecipients,
                            valueField: 'userId',
                            displayField: 'name',
                            filterPickList: true,
                            forceSelection: true,
                            queryMode: 'local',
                            anyMatch: true
                        }
                    ]
                }
            ]
        });

        this.sourceConfigPanel.add(this.sourceConfigBasePanel, this.sourceConfigRawitemPanel);

        opendxp.layout.refresh();
    },

    renderExcelSourceConfigPanel: function() {
        var config = this.dataport.sourceconfig;
        var additionalColumns = this.getColumnConfigForSourcetype('excel');

        // Rohdatenfeldkonfiguration
        if (typeof ExcelImportconfigModel == 'undefined') {
            var readerFields = [
                {name: 'fieldNo', allowBlank: false, type: 'integer'},
                { name: 'sort', allowBlank: false },
                {name: 'name', allowBlank: false}
            ];

            Ext.each(additionalColumns, function (column) {
                readerFields.push({name: column.dataIndex});
            });

            readerFields.push({name: 'data1', persist: false});

            Ext.define('ExcelImportconfigModel', {
                    extend: 'Ext.data.Model',
                    fields: readerFields,
                    idProperty: 'fieldNo',
                    root: 'fields'
                }
            );
        }

        var store = new Ext.data.JsonStore({
            model: 'ExcelImportconfigModel',
            proxy: {
                type: 'ajax',
                url: '/admin/SylphenDataBridge/importconfig/get-rawitemfield-config/' + this.dataport.id,
                reader: {
                    type: 'json',
                    rootProperty: 'fields',
                    messageProperty: 'errorMessage'
                }

            },
            listeners: {
                load: function () {
                    Ext.Ajax.request({
                        url: '/admin/SylphenDataBridge/importconfig/get-demo-data',
                        params: {
                            dataportId: this.dataportId
                        },
                        success: function (response) {
                            response = Ext.decode(response.responseText);

                            var store = this.sourceConfigRawitemPanel.getStore();
                            Ext.each(response.data, function (value) {
                                var record = store.findRecord('fieldNo', value.fieldNo, 0, false, true, true)

                                if (record) {
                                    record.set('data1', value.value);
                                }
                            }.bind(this));

                            this.sourceConfigRawitemPanel.getView().refresh();
                        }.bind(this)
                    });
                }.bind(this)
            },
            autoLoad: true
        });

        // store.setDefaultSort('fieldNo');
        var columnConfig = [];
        columnConfig.push({
            header: "#",
            width: 50,
            dataIndex: 'fieldNo',
            renderer: function (value, metaData, record) {
                if (record.get('sort') === 'DESC') {
                    metaData.tdAttr = 'data-qtip="' + t('pim.dataport-fields.sorting-direction.desc') + '"';
                    value += ' <img src="/bundles/opendxpadmin/img/flat-color-icons/alphabetical_sorting_za.svg" height="18" width="18" style="height:18px;vertical-align:middle">';
                } else {
                    metaData.tdAttr = 'data-qtip="' + t('pim.dataport-fields.sorting-direction.asc') + '"';
                }
                return value;
            }
        });
        columnConfig.push({
            header: t('pim.dataport-fields-name'),
            dataIndex: 'name',
            flex: 1,
            width: 'auto',
            renderer: function (value, metaData, record) {
                if (!value) {
                    return '';
                }

                var mappingRecords = this.mappingPanel.getStore().queryBy(function () { return true; }).getRange();
                var isMapped = false;
                var minOneFieldIsMapped = false;
                Ext.each(mappingRecords, function (mappingRecord) {
                    if (mappingRecord.get('field')) {
                        minOneFieldIsMapped = true;
                    }

                    if (record.get('fieldNo') === mappingRecord.get('field')) {
                        isMapped = true;
                        return false;
                    }

                    var mappingRecordSettings = mappingRecord.get('settings');
                    if (mappingRecordSettings.calculation) {
                        minOneFieldIsMapped = true;
                        if (this.dataport.javascriptEngine === 'php' && mappingRecordSettings.calculation.indexOf('$params[\'rawItemData\'][\'' + value + '\']') > -1) {
                            isMapped = true;
                            return false;
                        } else if (this.dataport.javascriptEngine === 'v8js' && (mappingRecordSettings.calculation.indexOf('params[\'rawItemData\'][\'' + value + '\']') > -1 || mappingRecordSettings.calculation.indexOf('params.rawItemData.' + value) > -1)) {
                            isMapped = true;
                            return false;
                        }
                    }
                }.bind(this));

                if (!isMapped && minOneFieldIsMapped) {
                    metaData.tdAttr = 'data-qtip="' + t('pim.mapping.variables.variable.not_in_use') + '"';
                    return '<s>' + Ext.util.Format.htmlEncode(value) + '</s>';
                }
                return Ext.util.Format.htmlEncode(value);
            }.bind(this),
            editor: {
                xtype: 'textfield',
                allowBlank: false,
                handleMouseEvents: true,
                listeners: {
                    render: function (cmp) {
                        Ext.get(cmp.getInputId()).on('mousedown', function (e) {
                            e.stopPropagation();
                        });
                    }
                }
            }
        });

        Ext.each(additionalColumns, function(column) {
            columnConfig.push(column);
        });

        columnConfig.push({
            header: t('pim.dataport-fields-ex1'),
            dataIndex: 'data1',
            renderer: function (value, metaData, record) {
                if (typeof value === 'undefined') {
                    return '';
                }
                return Ext.util.Format.htmlEncode(value);
            }
        });

        var autoCreate = function (response) {
            response = Ext.decode(response.responseText);

            if (typeof response.config !== 'undefined') {
                var store = this.sourceConfigRawitemPanel.getStore();
                Ext.each(response.config.fields, function (field) {
                    if (store.findExact('name', field.name) === -1 && store.findExact('column', field.name) === -1) {
                        this.onAddDataport();
                        var record = this.sourceConfigRawitemPanel.getSelectionModel().getSelection()[0];
                        record.set({
                            name: field.name,
                            column: field.column
                        });
                    }
                }.bind(this));
            } else {
                var uploadWindow = Ext.create('Ext.Window', {
                    layout: {
                        type: 'vbox',
                        align: 'stretch'
                    },
                    title: t('pim.dataport.auto_create.window.title'),
                    width: 500,
                    height: 300,
                    modal: true,
                    items: Ext.create('Ext.form.Panel', {
                        padding: 15,
                        itemId: 'uploadForm',
                        layout: {
                            type: 'vbox',
                            align: 'stretch'
                        },
                        items: [{
                            xtype: 'fileuploadfield',
                            allowBlank: false,
                            fieldLabel: t('pim.manual.importForm.uploadLabel'),
                            name: 'file',
                            buttonText: t('select_a_file'),
                            buttonCfg: {
                                iconCls: 'opendxp_icon_file'
                            },
                        }, {
                            xtype: 'hidden',
                            name: 'sourceType',
                            value: this.dataportPanel.getForm().findField('sourcetype').getValue(),
                        }, {
                            xtype: 'hidden',
                            name: 'hasHeader',
                            value: this.sourceConfigBasePanel.getForm().findField('hasHeader').getValue() ? 1 : 0,
                        }, {
                            xtype: 'hidden',
                            name: 'dataArea',
                            value: this.sourceConfigBasePanel.getForm().findField('dataArea').getValue(),
                        }, {
                            xtype: 'hidden',
                            name: 'sheet',
                            value: this.sourceConfigBasePanel.getForm().findField('sheet').getValue(),
                        }, {
                            xtype: 'hidden',
                            name: 'dataportId',
                            value: this.dataportId,
                        }, {
                            xtype: 'hidden',
                            name: 'csrfToken',
                            value: opendxp.settings['csrfToken'],
                        }]
                    }),
                    buttons: [{
                        text: t('pim.dataport.auto_create.window.button'),
                        handler: function () {
                            if (uploadWindow.queryById('uploadForm').getForm().isValid()) {
                                uploadWindow.queryById('uploadForm').getForm().submit({
                                    url: '/admin/SylphenDataBridge/importconfig/auto-create-rawdata-fields',
                                    waitMsg: t('pim.manual.importForm.waitMsg'),
                                    success: function (fp, response) {
                                        if (uploadWindow) {
                                            uploadWindow.close();
                                        }

                                        autoCreate(response.response);
                                    }.bind(this),
                                    failure: function (form, action) {
                                        if (action.result.msg) {
                                            Ext.MessageBox.alert(t('error'), t('pim.manual.importForm.failure') + action.result.msg);
                                        }
                                    }.bind(this)
                                });
                            }
                        }.bind(this)
                    }]
                });
                uploadWindow.show();
            }
        }.bind(this);

        this.sourceConfigRawitemPanel = Ext.create('Ext.grid.Panel', {
            title: '',
            store: store,
            columns: {
                defaults: {
                    menuDisabled : true,
                    sortable: false
                },
                items: columnConfig
            },
            flex: 1,
            border: false,
            frame: false,
            autoScroll: true,
            layout: 'fit',
            minHeight:300,
            selModel: {
                selType: 'rowmodel',
                mode: 'MULTI',
                ignoreRightMouseSelection: true
            },
            multiSelect: true,
            dockedItems: [{
                xtype: 'toolbar',
                dock: 'top',
                items: [
                    {
                        xtype: 'label',
                        html: t('pim.rawdatafields'),
                        cls: 'x-panel-header-title-default x-panel-header-default',
                        style: 'border:none;'
                    },
                    {
                        text: t('add'),
                        handler: this.onAddDataport.bind(this),
                        iconCls: "opendxp_icon_add"
                    },
                    '-',
                    {
                        text: t('delete'),
                        handler: function () {
                            this.onDeleteDataport();
                        }.bind(this),
                        iconCls: "opendxp_icon_delete"
                    },
                    '-',
                    {
                        text: t('pim.rawdatafields.bulk_edit'),
                        iconCls: "opendxp_icon_edit",
                        handler: function () {
                            var displayField = {
                                xtype: "displayfield",
                                region: "north",
                                hideLabel: true,
                                border: false,
                                value: t('pim.rawdatafields.bulk_edit.description') + ':<br>' + Ext.Array.filter(Ext.Array.map(columnConfig, function (column) {
                                    return column.dataIndex;
                                }), function (dataIndex) {
                                    return dataIndex !== 'data1';
                                }).join(',')
                            };

                            var data = [];
                            store.each(function (rec) {
                                var row = [];
                                Ext.Array.each(columnConfig, function (column) {
                                    if (column.dataIndex === 'data1') {
                                        return true;
                                    }
                                    row.push(rec.get(column.dataIndex));
                                });
                                data.push(row);
                            });

                            data = Ext.util.CSV.encode(data);

                            var textarea = new Ext.form.TextArea({
                                region: "center",
                                border: false,
                                value: data
                            });

                            var bulkEditWindow = new Ext.Window({
                                width: 800,
                                height: 500,
                                title: t('pim.rawdatafields.bulk_edit'),
                                iconCls: "opendxp_icon_edit",
                                layout: "fit",
                                modal: true,
                                resizable: true,
                                items: [new Ext.Panel({
                                    layout: "border",
                                    padding: '0 10',
                                    items: [displayField, textarea]
                                })],
                                buttons: [
                                    {
                                        text: t('apply'),
                                        iconCls: "opendxp_icon_save",
                                        handler: function () {
                                            store.removeAll();
                                            var content = textarea.getValue();
                                            if (content.length > 0) {
                                                var csvData = Ext.util.CSV.decode(content);

                                                for (var i = 0; i < csvData.length; i++) {
                                                    var row = csvData[i];

                                                    this.onAddDataport();
                                                    var record = this.sourceConfigRawitemPanel.getSelectionModel().getSelection()[0];

                                                    Ext.Array.each(columnConfig, function (column, index) {
                                                        record.set(column.dataIndex, row[index])
                                                    });
                                                }
                                            }

                                            bulkEditWindow.close();
                                        }.bind(this)
                                    },
                                    {
                                        text: t('cancel'),
                                        iconCls: "opendxp_icon_empty",
                                        handler: function () {
                                            bulkEditWindow.close();
                                        }
                                    }
                                ]
                            }).show();
                        }.bind(this)
                    },
                    '->',
                    {
                        text: t('pim.dataport.auto_create'),
                        handler: function() {
                            Ext.Ajax.request({
                                url: '/admin/SylphenDataBridge/importconfig/auto-create-rawdata-fields',
                                method: 'post',
                                params: {
                                    sourceType: this.dataportPanel.getForm().findField('sourcetype').getValue(),
                                    file: this.sourceConfigBasePanel.getForm().findField('file').getValue(),
                                    hasHeader: this.sourceConfigBasePanel.getForm().findField('hasHeader').getValue()?1:0,
                                    sheet: this.sourceConfigBasePanel.getForm().findField('sheet').getValue(),
                                    dataArea:  this.sourceConfigBasePanel.getForm().findField('dataArea').getValue(),
                                    dataportId: this.dataportId
                                },
                                success: autoCreate
                            });
                        }.bind(this),
                        iconCls: "opendxp_icon_clear_cache"
                    }]

            }],


            listeners: {
                beforeedit: function (editor, context) {
                    context.grid.getView().getPlugin('gridviewdragdrop').disable();
                },
                canceledit: function (editor, context) {
                    context.grid.getView().getPlugin('gridviewdragdrop').enable();
                },
                edit: function (editor, e) {
                    if (!e.record.get('name') && e.field === 'column' && e.value !== '') {
                        var isValid = true;

                        store.each(function (record) {
                            if (record.get('name') === e.value && record.get('fieldNo') !== e.record.get('fieldNo')) {
                                isValid = false;
                                return false;
                            }
                        });

                        if (isValid) {
                            e.record.set('name', e.value);
                        }
                    }

                    this.onAddDataport(false);

                    e.grid.getView().getPlugin('gridviewdragdrop').enable();
                }.bind(this),
                validateedit: function (editor, e) {
                    var isValid = true;

                    store.each(function (record) {
                        if (e.value !== '' && record.get(e.field) === e.value && e.record.get('fieldNo') !== record.get('fieldNo')) {
                            isValid = false;
                            return false;
                        }
                    });

                    if (!isValid) {
                        opendxp.helpers.showNotification(t("error"), t('pim.dataport-fields.duplicate'), "error");
                    }

                    return isValid;
                }.bind(this),
                celldblclick: function (grid, cell, cellIndex, record) {
                    if (columnConfig[cellIndex].dataIndex === 'data1') {
                        opendxp.helpers.copyStringToClipboard(record.get('data1'));
                        opendxp.helpers.showNotification(t("info"), t('pim.mapping.copied_to_clipboard_mapping'), 'info');
                    }
                },
                cellclick: function (grid, tdElement, cellIndex, record) {
                    if (columnConfig[cellIndex].dataIndex === 'fieldNo') {
                        if (record.get('sort') === 'ASC') {
                            record.set('sort', 'DESC');
                        } else {
                            record.set('sort', 'ASC');
                        }
                    }
                },
                itemcontextmenu: function(view, record, item, index, e) {
                    var menu = new Ext.menu.Menu();

                    var selectedRows = this.sourceConfigRawitemPanel.getSelectionModel().getSelection();
                    var clickedItemIsSelected = false;
                    if (selectedRows.length > 0) {
                        Ext.each(selectedRows, function (selectedRecord, index) {
                            if (record.get('fieldNo') === selectedRecord.get('fieldNo')) {
                                clickedItemIsSelected = true;
                                return false;
                            }
                        });
                    } else {
                        clickedItemIsSelected = true;
                    }

                    var records = [];
                    if (clickedItemIsSelected) {
                        records = selectedRows.reverse();
                    }

                    if (records.length === 0) {
                        records = selectedRows.length > 0 ? selectedRows : [record];
                    }

                    menu.add(new Ext.menu.Item({
                        text: t('pim.dataport.move-to-top'),
                        icon: "/bundles/opendxpadmin/img/flat-color-icons/up.svg",
                        handler: function (menuItem, e) {
                            Ext.each(records, function (record) {
                                store.data.remove(record);
                                store.data.insert(0, record);
                            });
                        }.bind(this),
                    }));

                    if (!clickedItemIsSelected) {
                        menu.add(new Ext.menu.Item({
                            text: t('pim.dataport.move-to-selection').replace('%s', selectedRows[selectedRows.length - 1].get('name')),
                            iconCls: "opendxp_icon_table_row",
                            handler: function (menuItem, e) {
                                store.data.remove(record);
                                store.data.insert(store.indexOf(selectedRows[selectedRows.length - 1]) + 1, record);
                            }.bind(this),
                        }));
                    }

                    if (record.get('sort') === null || record.get('sort') === 'ASC') {
                        menu.add(new Ext.menu.Item({
                            text: t('pim.dataport.sort-descending'),
                            icon: "/bundles/opendxpadmin/img/flat-color-icons/alphabetical_sorting_za.svg",
                            handler: function (menuItem, e) {
                                Ext.each(records, function (record) {
                                    record.set('sort', 'DESC');
                                });
                            }.bind(this),
                        }));
                    } else if (record.get('sort') === 'DESC') {
                        menu.add(new Ext.menu.Item({
                            text: t('pim.dataport.sort-ascending'),
                            icon: "/bundles/opendxpadmin/img/flat-color-icons/alphabetical_sorting_az.svg",
                            handler: function (menuItem, e) {
                                Ext.each(records, function (record) {
                                    record.set('sort', 'ASC');
                                });
                            }.bind(this),
                        }));
                    }

                    menu.add(new Ext.menu.Item({
                        text: t('add'),
                        iconCls: "opendxp_icon_add",
                        handler: this.onAddDataport.bind(this),
                    }));

                    menu.add(new Ext.menu.Item({
                        text: t('copy'),
                        iconCls: "opendxp_icon_copy",
                        handler: function () {
                            Ext.each(records, function (record) {
                                if (selectedRows.length === 1) {
                                    this.sourceConfigRawitemPanel.getSelectionModel().select(record);
                                } else {
                                    this.sourceConfigRawitemPanel.getSelectionModel().deselectAll();
                                }

                                var addedRecord = this.onAddDataport();
                                var name = record.get('name').replace(/(.*)(\d+)$/, function (input, namePrefix, trailingDigits) {
                                    return namePrefix + (parseInt(trailingDigits, 10) + 1);
                                });

                                addedRecord.set('name', name);

                                var column = record.get('column');
                                addedRecord.set('column', column);
                            }.bind(this));
                        }.bind(this)
                    }));

                    menu.add(new Ext.menu.Item({
                        text: t('delete'),
                        iconCls: "opendxp_icon_delete",
                        handler: function () {
                            this.onDeleteDataport(record);
                        }.bind(this)
                    }));

                    menu.showAt(e.getXY());

                    e.stopEvent();
                }.bind(this)
            },
            plugins: {
                ptype: 'cellediting',
                clicksToEdit: 2
            },
            viewConfig: {
                plugins: {
                    ptype: 'gridviewdragdrop',
                    pluginId: 'gridviewdragdrop',
                    dragText: t('pim.dataport.change-order-hint')
                }
            }
        });

        var fileInput = Ext.create('Ext.form.field.TextArea', {
            name: 'file',
            fieldLabel: t('pim.dataport.file'),
            value: this.getSourceconfig('file'),
            hidden: true,
            fieldStyle: 'background: url(/bundles/opendxpadmin/img/flat-color-icons/target.svg) right 5px center/20px no-repeat transparent !important;'
        });

        var editorId = Ext.id();
        var editor;
        var fieldConfig = {
            fieldLabel: t('pim.dataport.file'),
            name: 'fileEditor',
            value: '<div id="' + editorId + '" style="height:14px;width:100%;line-height:21px;background: url(/bundles/opendxpadmin/img/flat-color-icons/target.svg) right 5px center/20px no-repeat transparent !important;"></div>',
            fieldCls: 'x-form-text-default x-form-trigger-wrap-default',
            fieldStyle: 'padding-right: 0',
            style: 'opacity: 1',
            listeners: {
                render: function (el) {
                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                        ddGroup: "element",

                        getTargetFromEvent: function (e) {
                            return this.getEl();
                        },

                        onNodeOver: function (target, dd, e, data) {
                            data = data.records[0].data;

                            if (data.elementType == "asset") {
                                return Ext.dd.DropZone.prototype.dropAllowed;
                            }
                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                        },

                        onNodeDrop: function (target, dd, e, data) {
                            data = data.records[0].data;

                            if (data.elementType == "asset") {
                                editor.setValue(data.path);
                                return true;
                            }
                            return false;
                        }.bind(this)
                    });

                    this.getEl().on('dblclick', function () {
                        if (editor.getValue().length < 512) {
                            Ext.Ajax.request({
                                url: '/admin/element/get-subtype',
                                params: {
                                    id: editor.getValue(),
                                    type: 'asset'
                                },
                                success: function (response) {
                                    var res = Ext.decode(response.responseText);
                                    if (res.success) {
                                        opendxp.helpers.openElement(res.id, res.type, res.subtype);
                                    }
                                }
                            });
                        }
                    }.bind(this));
                },
                afterrender: function (cmp) {
                    editor = ace.edit(editorId);
                    editor.setTheme('ace/theme/chrome');
                    if (fileInput.getValue().indexOf("{{") === -1 && fileInput.getValue().indexOf("{%") === -1 && fileInput.getValue().indexOf("\n") > -1) {
                        editor.session.setMode('ace/mode/sh');
                    } else {
                        editor.session.setMode('ace/mode/twig');
                    }

                    var languageTools = ace.require("ace/ext/language_tools");
                    editor.completers = [languageTools.snippetCompleter, languageTools.textCompleter, {
                        getCompletions: function (editor, session, pos, prefix, callback) {
                            var variables = [];

                            var mappingRecords = this.mappingPanel.getStore().queryBy(function () { return true; }).getRange();
                            Ext.each(mappingRecords, function (mappingRecord) {
                                if (['__result_callback', '__result_action', '__init_action'].indexOf(mappingRecord.get('attributeKey')) > -1 || mappingRecord.get('attributeKey').indexOf('__virtual_') > -1) {
                                    return true;
                                }
                                var name = '';
                                if (mappingRecord.get('attributeKey').toLowerCase() !== mappingRecord.get('attributeName').toLowerCase()) {
                                    name = ' -> ' + mappingRecord.get('attributeName');
                                }
                                variables.push({ caption: '{{ ' + mappingRecord.get('attributeKey') + ' }}' + name, value: mappingRecord.get('attributeKey') + ' }}' });
                            });

                            variables.push({ caption: 'SFTP', value: 'sftp://user:password@hostname/path/to/files' });
                            variables.push({ caption: 'FTP', value: 'ftp://user:password@hostname/path/to/files' });
                            variables.push({ caption: 'FTPS', value: 'ftps://user:password@hostname/path/to/files' });
                            variables.push({ caption: t('pim.dataport.file.suggest.http_basic_auth'), value: 'https://user:password@example.org/import-file' });
                            variables.push({
                                caption: t('pim.dataport.file.suggest.http_post'), value: 'curl -X POST\n' +
                                    '  --header \'Content-Type: application/json\'\n' +
                                    '  --data \'{ "param1": "value1", "param2":"value2"}\' \n' +
                                    '  https://example.org'
                            });
                            variables.push({ caption: 'AWS S3', value: 's3://key:secret@region/bucket/path/to/files -- '+ t('pim.dataport.file.suggest.aws.hint') });

                            callback(null, variables);
                        }.bind(this)
                    }];
                    editor.setOptions({
                        showLineNumbers: false,
                        showGutter: false,
                        indentedSoftWrap: false,
                        showPrintMargin: false,
                        wrap: true,
                        //fontFamily: 'Open Sans, Helvetica Neue, helvetica, arial, verdana, sans-serif',
                        fontSize: "13px",
                        enableBasicAutocompletion: true,
                        enableSnippets: true,
                        enableLiveAutocompletion: true,
                        highlightActiveLine: false,
                        maxLines: 100
                    });

                    editor.setValue(fileInput.getValue() || '');
                    editor.clearSelection();

                    editor.on('blur', function () {
                        editor.clearSelection();
                    });
                    editor.on('change', function () {
                        fileInput.setValue(editor.getValue());
                        editorContainer.updateLayout();
                    }.bind(this));

                }.bind(this)
            }
        };
        var editorContainer = Ext.create("Ext.form.field.Display", fieldConfig);

        // Panel für Importkonfiguration
        this.sourceConfigBasePanel = new Ext.form.FormPanel({
            padding: 5,
            layout: {
                type: 'vbox',
                align: 'stretch'
            },
            fieldDefaults: {
                labelAlign: 'left',
                labelWidth: 200
            },
            items: [{
                xtype: 'panel',
                title: t('settings') + ': ' + this.dataportPanel.getForm().findField('sourcetype').getStore().findRecord("code", this.dataportPanel.getForm().findField('sourcetype').getValue()).get("label"),
                layout: {
                    type: 'vbox',
                    align: 'stretch'
                },
                bodyPadding: '10 0 0 10',
                items: [
                    fileInput,
                    editorContainer,
                    {
                        xtype: 'checkbox',
                        name: 'hasHeader',
                        fieldLabel: t('pim.dataport.excel.hasHeader'),
                        value: this.getSourceconfig('hasHeader') !== null ? this.getSourceconfig('hasHeader') : true
                    }, {
                        xtype: 'textfield',
                        name: 'sheet',
                        fieldLabel: t('pim.dataport.excel.sheet'),
                        value: this.getSourceconfig('sheet')
                    }, {
                        xtype: 'textfield',
                        name: 'dataArea',
                        fieldLabel: t('pim.dataport.excel.dataArea'),
                        value: this.getSourceconfig('dataArea'),
                        inputAttrTpl: " data-qtip='" + t('pim.dataport.excel.dataArea.tooltip') + "'"
                    }
                ]}, {
                xtype: 'panel',
                title: t('settings') + ': ' +((this.dataport.itemClass === "0" || this.dataportPanel.getForm().findField('itemClass').getValue() === "0") ? t('export') : t('import')),
                layout: {
                    type: 'vbox',
                    align: 'stretch'
                },
                bodyPadding: '10 0 0 10',
                items: [{
                        xtype: 'textfield',
                        name: 'itemFolder',
                        hidden: this.dataport.itemClass === "OpenDxp\\Model\\Asset" || this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Asset" || this.dataport.itemClass === "0" || this.dataportPanel.getForm().findField('itemClass').getValue() === "0" || (this.sourceConfigBasePanel && this.sourceConfigBasePanel.getForm().findField('mode').getValue() == '2'),
                        fieldLabel: t('pim.dataport_targetfolder'),
                        cls: "input_drop_target",
                        inputAttrTpl: 'data-qtip="' + t('pim.dataport.folder.pimcore_or_filesystem') + '"',
                        value: this.dataport.itemFolder,
                        listeners: {
                            render: function (el) {
                                var me = this;
                                var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                    ddGroup: "element",

                                    getTargetFromEvent: function (e) {
                                        return e.getTarget();
                                    },

                                    onNodeOver: function (target, dd, e, data) {
                                        data = data.records[0].data;

                                        if (data.elementType == "object" || data.elementType == "document") {
                                            return Ext.dd.DropZone.prototype.dropAllowed;
                                        }
                                        return Ext.dd.DropZone.prototype.dropNotAllowed;
                                    },

                                    onNodeDrop: function (target, dd, e, data) {
                                        data = data.records[0].data;

                                        if (data.elementType == "object" || data.elementType == "document") {
                                            target.value = data.path;
                                            return true;
                                        }
                                        return false;
                                    }.bind(this)
                                });

                                el.getEl().on('dblclick', function () {
                                    var elementType = 'object';
                                    if (this.dataport.itemClass === "OpenDxp\\Model\\Asset" || this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Asset") {
                                        elementType = 'asset';
                                    } else if (this.dataport.itemClass === "OpenDxp\\Model\\Document\\Page" || this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Document\\Page") {
                                        elementType = 'document';
                                    }
                                    opendxp.helpers.openElement(el.getValue(), elementType);
                                }.bind(this));
                            }.bind(this)
                        }
                    },
                    {
                        xtype: 'textfield',
                        name: 'masterDocument',
                        hidden: this.dataport.itemClass !== "OpenDxp\\Model\\Document\\Page" || this.dataportPanel.getForm().findField('itemClass').getValue() !== "OpenDxp\\Model\\Document\\Page",
                        fieldLabel: t('content_master_document'),
                        cls: "input_drop_target",
                        value: this.dataport.masterDocument,
                        listeners: {
                            render: function (el) {
                                var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                    ddGroup: "element",

                                    getTargetFromEvent: function (e) {
                                        return this.getEl();
                                    },

                                    onNodeOver: function (target, dd, e, data) {
                                        data = data.records[0].data;

                                        if (data.elementType == "document") {
                                            return Ext.dd.DropZone.prototype.dropAllowed;
                                        }
                                        return Ext.dd.DropZone.prototype.dropNotAllowed;
                                    },

                                    onNodeDrop: function (target, dd, e, data) {
                                        data = data.records[0].data;

                                        if (data.elementType == "document") {
                                            this.setValue(data.path);
                                            return true;
                                        }
                                        return false;
                                    }.bind(this)
                                });

                                this.getEl().on('dblclick', function () {
                                    opendxp.helpers.openElement(this.getValue(), 'document');
                                }.bind(this));
                            }
                        }
                    },
                    {
                        xtype: 'textfield',
                        name: 'assetSource',
                        fieldLabel: t('pim.dataport.excel.assetSource'),
                        cls: "input_drop_target",
                        inputAttrTpl: 'data-qtip="' + t('pim.dataport.folder.pimcore_or_filesystem') + '"',
                        listeners: {
                            render: function (el) {
                                var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                    ddGroup: "element",

                                    getTargetFromEvent: function (e) {
                                        return this.getEl();
                                    },

                                    onNodeOver: function (target, dd, e, data) {
                                        data = data.records[0].data;

                                        if (data.elementType == "asset" && data.type == "folder") {
                                            return Ext.dd.DropZone.prototype.dropAllowed;
                                        }
                                        return Ext.dd.DropZone.prototype.dropNotAllowed;
                                    },

                                    onNodeDrop: function (target, dd, e, data) {
                                        data = data.records[0].data;

                                        if (data.elementType == "asset" && data.type == "folder") {
                                            this.setValue(data.path);
                                            return true;
                                        }
                                        return false;
                                    }.bind(this)
                                });

                                this.getEl().on('dblclick', function () {
                                    opendxp.helpers.openElement(this.getValue(), 'asset');
                                }.bind(this));
                            }
                        },
                        value: this.getSourceconfig('assetSource')
                    },
                    {
                        xtype: 'textfield',
                        name: 'assetFolder',
                        fieldLabel: t('pim.dataport_assetfolder'),
                        inputAttrTpl: 'data-qtip="' + t('pim.dataport.folder.pimcore_or_filesystem') + '"',
                        cls: "input_drop_target",
                        listeners: {
                            render: function (el) {
                                var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                    ddGroup: "element",

                                    getTargetFromEvent: function (e) {
                                        return this.getEl();
                                    },

                                    onNodeOver: function (target, dd, e, data) {
                                        data = data.records[0].data;

                                        if (data.elementType == "asset" && data.type == "folder") {
                                            return Ext.dd.DropZone.prototype.dropAllowed;
                                        }
                                        return Ext.dd.DropZone.prototype.dropNotAllowed;
                                    },

                                    onNodeDrop: function (target, dd, e, data) {
                                        data = data.records[0].data;

                                        if (data.elementType == "asset" && data.type == "folder") {
                                            this.setValue(data.path);
                                            return true;
                                        }
                                        return false;
                                    }.bind(this)
                                });

                                this.getEl().on('dblclick', function () {
                                    opendxp.helpers.openElement(this.getValue(), 'asset');
                                }.bind(this));
                            }
                        },
                        value: this.dataport.assetFolder
                    },
                    {
                        xtype: 'checkbox',
                        name: 'autoImport',
                        fieldLabel: t('pim.dataport.auto-import'),
                        value: this.getSourceconfig('autoImport'),
                        autoEl: {
                            tag: 'div',
                            'data-qtip': (this.dataport.itemClass === "0" || this.dataportPanel.getForm().findField('itemClass').getValue() === "0") ? t('pim.dataport.auto-import.tooltip.export') : t('pim.dataport.auto-import.tooltip')
                        },
                        listeners: {
                            change: function (el, newValue) {
                                if (!newValue) {
                                    this.sourceConfigBasePanel.getForm().findField('incrementalExport').hide();
                                } else if (this.dataportPanel.getForm().findField('itemClass').getValue() === '0') {
                                    this.sourceConfigBasePanel.getForm().findField('incrementalExport').show();
                                }
                            }.bind(this)
                        }
                    },
                    {
                        xtype: 'checkbox',
                        name: 'incrementalExport',
                        fieldLabel: t('pim.dataport.incremental_export'),
                        value: this.getSourceconfig('incrementalExport'),
                        hidden: this.dataport.itemClass !== "0" || this.dataportPanel.getForm().findField('itemClass').getValue() !== "0",
                        autoEl: {
                            tag: 'div',
                            'data-qtip': t('pim.dataport.incremental_export.tooltip')
                        }
                    },
                    {
                        xtype: 'textfield',
                        name: 'archiveFolder',
                        fieldLabel: t('pim.dataport_archiveFolder'),
                        cls: "input_drop_target",
                        value: this.getSourceconfig('archiveFolder'),
                        listeners: {
                            render: function (el) {
                                var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                    ddGroup: "element",

                                    getTargetFromEvent: function (e) {
                                        return this.getEl();
                                    },

                                    onNodeOver: function (target, dd, e, data) {
                                        data = data.records[0].data;

                                        if (data.elementType == "asset" && data.type == "folder") {
                                            return Ext.dd.DropZone.prototype.dropAllowed;
                                        }
                                        return Ext.dd.DropZone.prototype.dropNotAllowed;
                                    },

                                    onNodeDrop: function (target, dd, e, data) {
                                        data = data.records[0].data;

                                        if (data.elementType == "asset" && data.type == "folder") {
                                            this.setValue(data.path);
                                            return true;
                                        }
                                        return false;
                                    }.bind(this)
                                });

                                this.getEl().on('dblclick', function () {
                                    opendxp.helpers.openElement(this.getValue(), 'asset');
                                }.bind(this));
                            }
                        }
                    }]

            },
                {
                    xtype: 'panel',
                    collapsible: true,
                    collapsed: true,
                    title: t('pim.dataport_advancedOptions'),
                    cls: 'x-panel-header-light',
                    style: 'border: none',
                    bodyPadding: '10 0 0 10',
                    headerOverCls: 'x-fieldset-header-text-collapsible',
                    titleCollapse: true,
                    fieldDefaults: {
                        labelAlign: 'left',
                        labelWidth: 200
                    },
                    layout: {
                        type: 'vbox',
                        align: 'stretch'
                    },
                    items: [
                        {
                            xtype: 'combo',
                            name: 'mode',
                            fieldLabel: t('pim.dataport_mode'),
                            editable: false,
                            forceSelection: true,
                            store: [
                                ['3', t('pim.dataport_mode.create_and_edit')],
                                ['1', t('pim.dataport_mode.create_only')],
                                ['2', t('pim.dataport_mode.edit_only')]
                            ],
                            value: this.dataport.mode
                        },
                        {
                            xtype: 'checkbox',
                            name: 'compatibilityMode',
                            value: this.dataport.compatibilityMode,
                            fieldLabel: t('pim.compatibilityMode'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.compatibilityMode.tooltip')
                            }
                        },
                        {
                            xtype: 'combo',
                            name: 'javascriptEngine',
                            fieldLabel: t('pim.dataport_javascriptEngine'),
                            hidden: true,
                            editable: false,
                            triggerAction: 'all',
                            store: Ext.create('Ext.data.JsonStore', {
                                model: 'JavascriptEngineModel',
                                proxy: {
                                    type: 'ajax',
                                    url: '/admin/SylphenDataBridge/importconfig/get-javascript-engines',
                                    reader: {
                                        type: 'json',
                                        rootProperty: 'data'
                                    }

                                },
                                autoLoad: true
                            }),
                            valueField: 'id',
                            displayField: 'name',
                            value: this.dataport.javascriptEngine,
                            listeners: {
                                select: function (comp, record, index) {
                                    if (comp.getValue() == "" || comp.getValue() == "&nbsp;") {
                                        comp.setValue(null);
                                    }
                                }
                            }
                        },
                        {
                            xtype: 'checkbox',
                            name: 'optimizeInheritance',
                            value: this.dataport.optimizeInheritance,
                            fieldLabel: t('pim.dataport_optimize_inheritance'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport_optimize_inheritance.tooltip')
                            }
                        },
                        {
                            xtype: 'numberfield',
                            minValue: 0,
                            decimalPrecision: 0,
                            name: 'parallelProcesses',
                            value: this.dataport.parallelProcesses,
                            fieldLabel: t('pim.dataport_parallel_processes'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport_parallel_processes.tooltip')
                            }
                        },
                        {
                            xtype: 'checkbox',
                            name: 'skipVersioning',
                            fieldLabel: t('pim.dataport_skip_versioning'),
                            value: this.dataport.skipVersioning,
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport_skip_versioning.tooltip')
                            },
                            hidden: true
                        }, {
                            xtype: 'textfield',
                            name: 'idPrefix',
                            value: this.dataport.idPrefix,
                            fieldLabel: t('pim.dataport_idprefix'),
                            allowBlank: true,
                            hidden: true
                        }, {
                            xtype: 'textfield',
                            name: 'categoryClass',
                            value: this.dataport.categoryClass,
                            fieldLabel: t('pim.dataport_categoryclass'),
                            allowBlank: true,
                            hidden: true
                        }, {
                            xtype: 'textfield',
                            name: 'fieldnameProducts',
                            value: this.dataport.fieldnameProducts,
                            fieldLabel: t('pim.dataport_fieldnameProducts'),
                            allowBlank: true,
                            hidden: true
                        },
                        {
                            xtype: 'tagfield',
                            name: 'error_recipients[]',
                            fieldLabel: t('pim.dataport.send_error_notification'),
                            store: Ext.create('Ext.data.JsonStore', {
                                proxy: {
                                    type: 'ajax',
                                    url: '/admin/SylphenDataBridge/importconfig/get-recipients/' + this.dataport.id,
                                    fields: ['name', 'userId'],
                                    reader: {
                                        type: 'json',
                                        rootProperty: 'data'
                                    }

                                },
                                autoLoad: true
                            }),
                            value: this.dataport.errorRecipients,
                            valueField: 'userId',
                            displayField: 'name',
                            filterPickList: true,
                            forceSelection: true,
                            queryMode: 'local',
                            anyMatch: true
                        }
                    ]
                }
            ]
        });

        this.sourceConfigPanel.add(this.sourceConfigBasePanel, this.sourceConfigRawitemPanel);

        opendxp.layout.refresh();
    },

    renderJsonSourceConfigPanel: function () {
        var additionalColumns = this.getColumnConfigForSourcetype('json');
        var readerFields = [
            {name: 'fieldNo', allowBlank: false, type: 'integer'},
            { name: 'sort', allowBlank: false },
            {name: 'name', allowBlank: false}
        ];

        Ext.each(additionalColumns, function (column) {
            readerFields.push({name: column.dataIndex});
        });

        readerFields.push({name: 'data1', persist: false});

        if (typeof JsonImportconfigModel === 'undefined') {
            Ext.define('JsonImportconfigModel', {
                extend: 'Ext.data.Model',
                fields: readerFields,
                idProperty: 'fieldNo',
                root: 'fields'
            });
        }

        var store = new Ext.data.JsonStore({
            model: 'JsonImportconfigModel',
            proxy: {
                type: 'ajax',
                url: '/admin/SylphenDataBridge/importconfig/get-rawitemfield-config/' + this.dataport.id,
                reader: {
                    type: 'json',
                    rootProperty: 'fields',
                    messageProperty: 'errorMessage'
                }

            },
            listeners: {
                load: function () {
                    Ext.Ajax.request({
                        url: '/admin/SylphenDataBridge/importconfig/get-demo-data',
                        params: {
                            dataportId: this.dataportId
                        },
                        success: function (response) {
                            response = Ext.decode(response.responseText);

                            var store = this.sourceConfigRawitemPanel.getStore();
                            Ext.each(response.data, function (value) {
                                var record = store.findRecord('fieldNo', value.fieldNo, 0, false, true, true)

                                if (record) {
                                    record.set('data1', value.value);
                                }
                            }.bind(this));

                            this.sourceConfigRawitemPanel.getView().refresh();
                        }.bind(this)
                    });
                }.bind(this)
            },
            autoLoad: true
        });

        var columnConfig = [];
        columnConfig.push({
            header: "#",
            width: 50,
            dataIndex: 'fieldNo',
            renderer: function (value, metaData, record) {
                if(record.get('sort') === 'DESC') {
                    metaData.tdAttr = 'data-qtip="' + t('pim.dataport-fields.sorting-direction.desc') + '"';
                    value += ' <img src="/bundles/opendxpadmin/img/flat-color-icons/alphabetical_sorting_za.svg" height="18" width="18" style="height:18px;vertical-align:middle">';
                } else {
                    metaData.tdAttr = 'data-qtip="' + t('pim.dataport-fields.sorting-direction.asc') + '"';
                }
                return value;
            }
        });
        columnConfig.push({
            header: t('pim.dataport-fields-name'),
            width: 'auto',
            dataIndex: 'name',
            flex: 1,
            renderer: function (value, metaData, record) {
                if (!value) {
                    return '';
                }

                var mappingRecords = this.mappingPanel.getStore().queryBy(function () { return true; }).getRange();
                var isMapped = false;
                var minOneFieldIsMapped = false;
                Ext.each(mappingRecords, function (mappingRecord) {
                    if (mappingRecord.get('field')) {
                        minOneFieldIsMapped = true;
                    }

                    if (record.get('fieldNo') === mappingRecord.get('field')) {
                        isMapped = true;
                        return false;
                    }

                    var mappingRecordSettings = mappingRecord.get('settings');
                    if (mappingRecordSettings.calculation) {
                        minOneFieldIsMapped = true;
                        if (this.dataport.javascriptEngine === 'php' && mappingRecordSettings.calculation.indexOf('$params[\'rawItemData\'][\'' + value + '\']') > -1) {
                            isMapped = true;
                            return false;
                        } else if (this.dataport.javascriptEngine === 'v8js' && (mappingRecordSettings.calculation.indexOf('params[\'rawItemData\'][\'' + value + '\']') > -1 || mappingRecordSettings.calculation.indexOf('params.rawItemData.' + value) > -1)) {
                            isMapped = true;
                            return false;
                        }
                    }
                }.bind(this));

                if (!isMapped && minOneFieldIsMapped) {
                    metaData.tdAttr = 'data-qtip="' + t('pim.mapping.variables.variable.not_in_use') + '"';
                    return '<s>' + Ext.util.Format.htmlEncode(value) + '</s>';
                }
                return Ext.util.Format.htmlEncode(value);
            }.bind(this),
            editor: {
                xtype: 'textfield',
                allowBlank: false,
                handleMouseEvents: true,
                listeners: {
                    render: function (cmp) {
                        Ext.get(cmp.getInputId()).on('mousedown', function (e) {
                            e.stopPropagation();
                        });
                    }
                }
            }
        });

        Ext.each(additionalColumns, function (column) {
            columnConfig.push(column);
        });

        columnConfig.push({
            header: t('pim.dataport-fields-ex1'),
            dataIndex: 'data1',
            width: 'auto',
            flex: 1,
            renderer: function (value, metaData, record) {
                if (typeof value === 'undefined') {
                    return '';
                }
                return Ext.util.Format.htmlEncode(value);
            }
        });

        var autoCreate = function (response) {
            response = Ext.decode(response.responseText);

            if (typeof response.config !== 'undefined') {
                var store = this.sourceConfigRawitemPanel.getStore();
                Ext.each(response.config.fields, function (field) {
                    if (store.findExact('name', field.name) === -1 && (typeof field['json-path'] === 'undefined' || store.findExact('json-path', field['json-path']) === -1)) {
                        this.onAddDataport();
                        var record = this.sourceConfigRawitemPanel.getSelectionModel().getSelection()[0];
                        record.set({
                            name: field.name,
                            "json-path": field["json-path"]
                        });
                    }
                }.bind(this));
            } else {
                var uploadWindow = Ext.create('Ext.Window', {
                    layout: {
                        type: 'vbox',
                        align: 'stretch'
                    },
                    title: t('pim.dataport.auto_create.window.title'),
                    width: 500,
                    height: 300,
                    modal: true,
                    items: Ext.create('Ext.form.Panel', {
                        padding: 15,
                        itemId: 'uploadForm',
                        layout: {
                            type: 'vbox',
                            align: 'stretch'
                        },
                        items: [{
                            xtype: 'fileuploadfield',
                            allowBlank: false,
                            fieldLabel: t('pim.manual.importForm.uploadLabel'),
                            name: 'file',
                            buttonText: t('select_a_file'),
                            buttonCfg: {
                                iconCls: 'opendxp_icon_file'
                            },
                        }, {
                            xtype: 'hidden',
                            name: 'sourceType',
                            value: this.dataportPanel.getForm().findField('sourcetype').getValue(),
                        }, {
                            xtype: 'hidden',
                            name: 'item-json-path',
                            value: this.sourceConfigBasePanel.getForm().findField('item-json-path').getValue() ? 1 : 0,
                        }, {
                            xtype: 'hidden',
                            name: 'dataportId',
                            value: this.dataportId,
                        }, {
                            xtype: 'hidden',
                            name: 'csrfToken',
                            value: opendxp.settings['csrfToken'],
                        }]
                    }),
                    buttons: [{
                        text: t('pim.dataport.auto_create.window.button'),
                        handler: function () {
                            if (uploadWindow.queryById('uploadForm').getForm().isValid()) {
                                uploadWindow.queryById('uploadForm').getForm().submit({
                                    url: '/admin/SylphenDataBridge/importconfig/auto-create-rawdata-fields',
                                    waitMsg: t('pim.manual.importForm.waitMsg'),
                                    success: function (fp, response) {
                                        if (uploadWindow) {
                                            uploadWindow.close();
                                        }

                                        autoCreate(response.response);
                                    }.bind(this),
                                    failure: function (form, action) {
                                        if (action.result.msg) {
                                            Ext.MessageBox.alert(t('error'), t('pim.manual.importForm.failure') + action.result.msg);
                                        }
                                    }.bind(this)
                                });
                            }
                        }.bind(this)
                    }]
                });
                uploadWindow.show();
            }
        }.bind(this);

        this.sourceConfigRawitemPanel = Ext.create('Ext.grid.Panel', {
            title: '',
            store: store,
            columns: {
                defaults: {
                    menuDisabled : true,
                    sortable: false
                },
                items: columnConfig
            },
            flex: 1,
            border: false,
            frame: false,
            autoScroll: true,
            layout: 'fit',
            minHeight: 300,
            selModel: {
                selType: 'rowmodel',
                mode: 'MULTI',
                ignoreRightMouseSelection: true
            },
            multiSelect: true,
            dockedItems: [{
                xtype: 'toolbar',
                dock: 'top',
                items: [
                {
                    xtype: 'label',
                    html: t('pim.rawdatafields'),
                    cls: 'x-panel-header-title-default x-panel-header-default',
                    style: 'border:none;'
                },{
                    text: t('add'),
                    handler: this.onAddDataport.bind(this),
                    iconCls: "opendxp_icon_add"
                },
                '-',
                {
                    text: t('delete'),
                    handler: function () {
                        this.onDeleteDataport();
                    }.bind(this),
                    iconCls: "opendxp_icon_delete"
                },
                    '-',
                    {
                        text: t('pim.rawdatafields.bulk_edit'),
                        iconCls: "opendxp_icon_edit",
                        handler: function () {
                            var displayField = {
                                xtype: "displayfield",
                                region: "north",
                                hideLabel: true,
                                border: false,
                                value: t('pim.rawdatafields.bulk_edit.description') + ':<br>' + Ext.Array.filter(Ext.Array.map(columnConfig, function (column) {
                                    return column.dataIndex;
                                }), function (dataIndex) {
                                    return dataIndex !== 'data1';
                                }).join(',')
                            };

                            var data = [];
                            store.each(function (rec) {
                                var row = [];
                                Ext.Array.each(columnConfig, function (column) {
                                    if (column.dataIndex === 'data1') {
                                        return true;
                                    }
                                    row.push(rec.get(column.dataIndex));
                                });
                                data.push(row);
                            });

                            data = Ext.util.CSV.encode(data);

                            var textarea = new Ext.form.TextArea({
                                region: "center",
                                border: false,
                                value: data
                            });

                            var bulkEditWindow = new Ext.Window({
                                width: 800,
                                height: 500,
                                title: t('pim.rawdatafields.bulk_edit'),
                                iconCls: "opendxp_icon_edit",
                                layout: "fit",
                                modal: true,
                                resizable: true,
                                items: [new Ext.Panel({
                                    layout: "border",
                                    padding: '0 10',
                                    items: [displayField, textarea]
                                })],
                                buttons: [
                                    {
                                        text: t('apply'),
                                        iconCls: "opendxp_icon_save",
                                        handler: function () {
                                            store.removeAll();
                                            var content = textarea.getValue();
                                            if (content.length > 0) {
                                                var csvData = Ext.util.CSV.decode(content);

                                                for (var i = 0; i < csvData.length; i++) {
                                                    var row = csvData[i];

                                                    this.onAddDataport();
                                                    var record = this.sourceConfigRawitemPanel.getSelectionModel().getSelection()[0];

                                                    Ext.Array.each(columnConfig, function (column, index) {
                                                        record.set(column.dataIndex, row[index])
                                                    });
                                                }
                                            }

                                            bulkEditWindow.close();
                                        }.bind(this)
                                    },
                                    {
                                        text: t('cancel'),
                                        iconCls: "opendxp_icon_empty",
                                        handler: function () {
                                            bulkEditWindow.close();
                                        }
                                    }
                                ]
                            }).show();
                        }.bind(this)
                    },
                '->',
                {
                    text: t('pim.dataport.auto_create'),
                    handler: function() {
                        Ext.Ajax.request({
                            url: '/admin/SylphenDataBridge/importconfig/auto-create-rawdata-fields',
                            method: 'post',
                            params: {
                                sourceType: this.dataportPanel.getForm().findField('sourcetype').getValue(),
                                file: this.sourceConfigBasePanel.getForm().findField('file').getValue(),
                                "item-json-path": this.sourceConfigBasePanel.getForm().findField('item-json-path').getValue(),
                                dataportId: this.dataportId
                            },
                            success: autoCreate
                        });
                    }.bind(this),
                    iconCls: "opendxp_icon_clear_cache"
                }]

            }],
            listeners: {
                beforeedit: function (editor, context) {
                    context.grid.getView().getPlugin('gridviewdragdrop').disable();
                },
                canceledit: function (editor, context) {
                    context.grid.getView().getPlugin('gridviewdragdrop').enable();
                },
                edit: function (editor, e) {
                    if (!e.record.get('name') && e.field === 'json-path' && e.value !== '') {
                        var isValid = true;

                        store.each(function (record) {
                            if (record.get('name') === e.value && record.get('fieldNo') !== e.record.get('fieldNo')) {
                                isValid = false;
                                return false;
                            }
                        });

                        if (isValid) {
                            e.record.set('name', e.value);
                        }
                    }

                    this.onAddDataport(false);

                    e.grid.getView().getPlugin('gridviewdragdrop').enable();
                }.bind(this),
                validateedit: function (editor, e) {
                    var isValid = true;

                    store.each(function (record) {
                        if (e.value !== '' && record.get(e.field) === e.value && e.record.get('fieldNo') !== record.get('fieldNo')) {
                            isValid = false;
                            return false;
                        }
                    });

                    if (!isValid) {
                        opendxp.helpers.showNotification(t("error"), t('pim.dataport-fields.duplicate'), "error");
                    }

                    return isValid;
                }.bind(this),
                celldblclick: function (grid, cell, cellIndex, record) {
                    if (columnConfig[cellIndex].dataIndex === 'data1') {
                        opendxp.helpers.copyStringToClipboard(record.get('data1'));
                        opendxp.helpers.showNotification(t("info"), t('pim.mapping.copied_to_clipboard_mapping'), 'info');
                    }
                },
                cellclick: function (grid, tdElement, cellIndex, record) {
                    if (columnConfig[cellIndex].dataIndex === 'fieldNo') {
                        if(record.get('sort') === 'ASC') {
                            record.set('sort', 'DESC');
                        } else {
                            record.set('sort', 'ASC');
                        }
                    }
                },
                itemcontextmenu: function(view, record, item, index, e) {
                    var menu = new Ext.menu.Menu();

                    var selectedRows = this.sourceConfigRawitemPanel.getSelectionModel().getSelection();
                    var clickedItemIsSelected = false;
                    if (selectedRows.length > 0) {
                        Ext.each(selectedRows, function (selectedRecord, index) {
                            if (record.get('fieldNo') === selectedRecord.get('fieldNo')) {
                                clickedItemIsSelected = true;
                                return false;
                            }
                        });
                    } else {
                        clickedItemIsSelected = true;
                    }

                    var records = [];
                    if (clickedItemIsSelected) {
                        records = selectedRows.reverse();
                    }

                    if (records.length === 0) {
                        records = selectedRows.length > 0 ? selectedRows : [record];
                    }

                    menu.add(new Ext.menu.Item({
                        text: t('pim.dataport.move-to-top'),
                        icon: "/bundles/opendxpadmin/img/flat-color-icons/up.svg",
                        handler: function (menuItem, e) {
                            Ext.each(records, function (record) {
                                store.data.remove(record);
                                store.data.insert(0, record);
                            });
                        }.bind(this),
                    }));

                    if (!clickedItemIsSelected) {
                        menu.add(new Ext.menu.Item({
                            text: t('pim.dataport.move-to-selection').replace('%s', selectedRows[selectedRows.length - 1].get('name')),
                            iconCls: "opendxp_icon_table_row",
                            handler: function (menuItem, e) {
                                store.data.remove(record);
                                store.data.insert(store.indexOf(selectedRows[selectedRows.length - 1]) + 1, record);
                            }.bind(this),
                        }));
                    }

                    if (record.get('sort') === null || record.get('sort') === 'ASC') {
                        menu.add(new Ext.menu.Item({
                            text: t('pim.dataport.sort-descending'),
                            icon: "/bundles/opendxpadmin/img/flat-color-icons/alphabetical_sorting_za.svg",
                            handler: function (menuItem, e) {
                                Ext.each(records, function (record) {
                                    record.set('sort', 'DESC');
                                });
                            }.bind(this),
                        }));
                    } else if (record.get('sort') === 'DESC') {
                        menu.add(new Ext.menu.Item({
                            text: t('pim.dataport.sort-ascending'),
                            icon: "/bundles/opendxpadmin/img/flat-color-icons/alphabetical_sorting_az.svg",
                            handler: function (menuItem, e) {
                                Ext.each(records, function (record) {
                                    record.set('sort', 'ASC');
                                });
                            }.bind(this),
                        }));
                    }

                    menu.add(new Ext.menu.Item({
                        text: t('add'),
                        iconCls: "opendxp_icon_add",
                        handler: this.onAddDataport.bind(this),
                    }));

                    menu.add(new Ext.menu.Item({
                        text: t('copy'),
                        iconCls: "opendxp_icon_copy",
                        handler: function () {
                            Ext.each(records, function (record) {
                                if (selectedRows.length === 1) {
                                    this.sourceConfigRawitemPanel.getSelectionModel().select(record);
                                } else {
                                    this.sourceConfigRawitemPanel.getSelectionModel().deselectAll();
                                }

                                var addedRecord = this.onAddDataport();
                                var name = record.get('name').replace(/(.*)(\d+)$/, function (input, namePrefix, trailingDigits) {
                                    return namePrefix + (parseInt(trailingDigits, 10) + 1);
                                });

                                addedRecord.set('name', name);

                                var column = record.get('json-path');
                                addedRecord.set('json-path', column);
                            }.bind(this));
                        }.bind(this)
                    }));

                    menu.add(new Ext.menu.Item({
                        text: t('delete'),
                        iconCls: "opendxp_icon_delete",
                        handler: function () {
                            this.onDeleteDataport(record);
                        }.bind(this)
                    }));

                    menu.showAt(e.getXY());

                    e.stopEvent();
                }.bind(this)
            },
            plugins: {
                ptype: 'cellediting',
                clicksToEdit: 2
            },
            viewConfig: {
                plugins: {
                    ptype: 'gridviewdragdrop',
                    pluginId: 'gridviewdragdrop',
                    dragText: t('pim.dataport.change-order-hint')
                }
            }
        });

        var fileInput = Ext.create('Ext.form.field.TextArea', {
            name: 'file',
            fieldLabel: t('pim.dataport.file'),
            value: this.getSourceconfig('file'),
            hidden: true,
            fieldStyle: 'background: url(/bundles/opendxpadmin/img/flat-color-icons/target.svg) right 5px center/20px no-repeat transparent !important;'
        });

        var editorId = Ext.id();
        var editor;
        var fieldConfig = {
            fieldLabel: t('pim.dataport.file'),
            name: 'fileEditor',
            value: '<div id="' + editorId + '" style="height:14px;width:100%;line-height:21px;background: url(/bundles/opendxpadmin/img/flat-color-icons/target.svg) right 5px center/20px no-repeat transparent !important;"></div>',
            fieldCls: 'x-form-text-default x-form-trigger-wrap-default',
            fieldStyle: 'padding-right: 0',
            style: 'opacity: 1',
            listeners: {
                render: function (el) {
                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                        ddGroup: "element",

                        getTargetFromEvent: function (e) {
                            return this.getEl();
                        },

                        onNodeOver: function (target, dd, e, data) {
                            data = data.records[0].data;

                            if (data.elementType == "asset") {
                                return Ext.dd.DropZone.prototype.dropAllowed;
                            }
                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                        },

                        onNodeDrop: function (target, dd, e, data) {
                            data = data.records[0].data;

                            if (data.elementType == "asset") {
                                editor.setValue(data.path);
                                return true;
                            }
                            return false;
                        }.bind(this)
                    });

                    this.getEl().on('dblclick', function () {
                        if (editor.getValue().length < 512) {
                            Ext.Ajax.request({
                                url: '/admin/element/get-subtype',
                                params: {
                                    id: editor.getValue(),
                                    type: 'asset'
                                },
                                success: function (response) {
                                    var res = Ext.decode(response.responseText);
                                    if (res.success) {
                                        opendxp.helpers.openElement(res.id, res.type, res.subtype);
                                    }
                                }
                            });
                        }
                    }.bind(this));
                },
                afterrender: function (cmp) {
                    editor = ace.edit(editorId);
                    editor.setTheme('ace/theme/chrome');
                    if (fileInput.getValue().indexOf("{{") === -1 && fileInput.getValue().indexOf("{%") === -1 && fileInput.getValue().indexOf("\n") > -1) {
                        editor.session.setMode('ace/mode/sh');
                    } else {
                        editor.session.setMode('ace/mode/twig');
                    }

                    var languageTools = ace.require("ace/ext/language_tools");
                    editor.completers = [languageTools.snippetCompleter, languageTools.textCompleter, {
                        getCompletions: function (editor, session, pos, prefix, callback) {
                            var variables = [];

                            var mappingRecords = this.mappingPanel.getStore().queryBy(function () { return true; }).getRange();
                            Ext.each(mappingRecords, function (mappingRecord) {
                                if (['__result_callback', '__result_action', '__init_action'].indexOf(mappingRecord.get('attributeKey')) > -1 || mappingRecord.get('attributeKey').indexOf('__virtual_') > -1) {
                                    return true;
                                }
                                var name = '';
                                if (mappingRecord.get('attributeKey').toLowerCase() !== mappingRecord.get('attributeName').toLowerCase()) {
                                    name = ' -> ' + mappingRecord.get('attributeName');
                                }
                                variables.push({ caption: '{{ ' + mappingRecord.get('attributeKey') + ' }}' + name, value: mappingRecord.get('attributeKey') + ' }}' });
                            });

                            variables.push({ caption: 'SFTP', value: 'sftp://user:password@hostname/path/to/files' });
                            variables.push({ caption: 'FTP', value: 'ftp://user:password@hostname/path/to/files' });
                            variables.push({ caption: 'FTPS', value: 'ftps://user:password@hostname/path/to/files' });
                            variables.push({ caption: t('pim.dataport.file.suggest.http_basic_auth'), value: 'https://user:password@example.org/import-file' });
                            variables.push({
                                caption: t('pim.dataport.file.suggest.http_post'), value: 'curl -X POST\n' +
                                    '  --header \'Content-Type: application/json\'\n' +
                                    '  --data \'{ "param1": "value1", "param2":"value2"}\' \n' +
                                    '  https://example.org'
                            });
                            variables.push({ caption: 'AWS S3', value: 's3://key:secret@region/bucket/path/to/files -- check ~/.aws/credentials to get your key + secret (after you have executed aws configure)' });
                            variables.push({ caption: t('pim.dataport.file.suggest.icecat'), value: 'https://live.icecat.biz/api?UserName=openIcecat-live&Language=en&GTIN={{ GTIN }}' });

                            callback(null, variables);
                        }.bind(this)
                    }];
                    editor.setOptions({
                        showLineNumbers: false,
                        showGutter: false,
                        indentedSoftWrap: false,
                        showPrintMargin: false,
                        wrap: true,
                        //fontFamily: 'Open Sans, Helvetica Neue, helvetica, arial, verdana, sans-serif',
                        fontSize: "13px",
                        enableBasicAutocompletion: true,
                        enableSnippets: true,
                        enableLiveAutocompletion: true,
                        highlightActiveLine: false,
                        maxLines: 100
                    });

                    editor.setValue(fileInput.getValue() || '');
                    editor.clearSelection();

                    editor.on('blur', function () {
                        editor.clearSelection();
                    });
                    editor.on('change', function () {
                        fileInput.setValue(editor.getValue());
                        editorContainer.updateLayout();
                    }.bind(this));

                }.bind(this)
            }
        };
        var editorContainer = Ext.create("Ext.form.field.Display", fieldConfig);

        // Panel für Importkonfiguration
        this.sourceConfigBasePanel = new Ext.form.FormPanel({
            padding: 5,
            layout: {
                type: 'vbox',
                align: 'stretch'
            },
            fieldDefaults: {
                labelAlign: 'left',
                labelWidth: 200
            },
            items: [{
                xtype: 'panel',
                title: t('settings') + ': ' + this.dataportPanel.getForm().findField('sourcetype').getStore().findRecord("code", this.dataportPanel.getForm().findField('sourcetype').getValue()).get("label"),
                layout: {
                    type: 'vbox',
                    align: 'stretch'
                },
                bodyPadding: '10 0 0 10',
                items: [
                  fileInput,
                    editorContainer,
                    {
                        xtype: 'textfield',
                        name: 'item-json-path',
                        fieldLabel: t('pim.dataport.json.item-json-path'),
                        value: this.getSourceconfig('item-json-path')
                    }]
            },
                {
                    xtype: 'panel',
                    itemId: 'targetSettings',
                    title: t('settings') + ': ' +((this.dataport.itemClass === "0" || this.dataportPanel.getForm().findField('itemClass').getValue() === "0") ? t('export') : t('import')),
                    layout: {
                        type: 'vbox',
                        align: 'stretch'
                    },
                    bodyPadding: '10 0 0 10',
                    items: [{
                            xtype: 'textfield',
                            name: 'itemFolder',
                            hidden: this.dataport.itemClass === "OpenDxp\\Model\\Asset" || this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Asset" || this.dataport.itemClass === "0" || this.dataportPanel.getForm().findField('itemClass').getValue() === "0" || (this.sourceConfigBasePanel && this.sourceConfigBasePanel.getForm().findField('mode').getValue() == '2'),
                            fieldLabel: t('pim.dataport_targetfolder'),
                            cls: "input_drop_target",
                            value: this.dataport.itemFolder,
                            listeners: {
                                render: function (el) {
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return e.getTarget();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "object" || data.elementType == "document") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "object" || data.elementType == "document") {
                                                target.value = data.path;
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    el.getEl().on('dblclick', function () {
                                        var elementType = 'object';
                                        if (this.dataport.itemClass === "OpenDxp\\Model\\Asset" || this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Asset") {
                                            elementType = 'asset';
                                        } else if (this.dataport.itemClass === "OpenDxp\\Model\\Document\\Page" || this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Document\\Page") {
                                            elementType = 'document';
                                        }
                                        opendxp.helpers.openElement(el.getValue(), elementType);
                                    }.bind(this));
                                }.bind(this)
                            }
                        },
                        {
                            xtype: 'textfield',
                            name: 'masterDocument',
                            hidden: this.dataport.itemClass !== "OpenDxp\\Model\\Document\\Page" || this.dataportPanel.getForm().findField('itemClass').getValue() !== "OpenDxp\\Model\\Document\\Page",
                            fieldLabel: t('content_master_document'),
                            cls: "input_drop_target",
                            value: this.dataport.masterDocument,
                            listeners: {
                                render: function (el) {
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return this.getEl();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "document") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "document") {
                                                this.setValue(data.path);
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    this.getEl().on('dblclick', function () {
                                        opendxp.helpers.openElement(this.getValue(), 'document');
                                    }.bind(this));
                                }
                            }
                        },
                        {
                            xtype: 'textfield',
                            name: 'assetSource',
                            fieldLabel: t('pim.dataport.csv.assetSource'),
                            cls: "input_drop_target",
                            inputAttrTpl: 'data-qtip="' + t('pim.dataport.folder.pimcore_or_filesystem') + '"',
                            listeners: {
                                render: function (el) {
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return this.getEl();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                this.setValue(data.path);
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    this.getEl().on('dblclick', function () {
                                        opendxp.helpers.openElement(this.getValue(), 'asset');
                                    }.bind(this));
                                }
                            },
                            value: this.getSourceconfig('assetSource')
                        },
                        {
                            xtype: 'textfield',
                            name: 'assetFolder',
                            fieldLabel: t('pim.dataport_assetfolder'),
                            inputAttrTpl: 'data-qtip="' + t('pim.dataport.folder.pimcore_or_filesystem') + '"',
                            cls: "input_drop_target",
                            listeners: {
                                render: function (el) {
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return this.getEl();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                this.setValue(data.path);
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    this.getEl().on('dblclick', function () {
                                        opendxp.helpers.openElement(this.getValue(), 'asset');
                                    }.bind(this));
                                }
                            },
                            value: this.dataport.assetFolder
                        },
                        {
                            xtype: 'checkbox',
                            name: 'autoImport',
                            fieldLabel: t('pim.dataport.auto-import'),
                            value: this.getSourceconfig('autoImport'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': (this.dataport.itemClass === "0" || this.dataportPanel.getForm().findField('itemClass').getValue() === "0") ? t('pim.dataport.auto-import.tooltip.export') : t('pim.dataport.auto-import.tooltip')
                            },
                            listeners: {
                                change: function (el, newValue) {
                                    if (!newValue) {
                                        this.sourceConfigBasePanel.getForm().findField('incrementalExport').hide();
                                    } else if (this.dataportPanel.getForm().findField('itemClass').getValue() === '0') {
                                        this.sourceConfigBasePanel.getForm().findField('incrementalExport').show();
                                    }
                                }.bind(this)
                            }
                        },
                        {
                            xtype: 'checkbox',
                            name: 'incrementalExport',
                            fieldLabel: t('pim.dataport.incremental_export'),
                            value: this.getSourceconfig('incrementalExport'),
                            hidden: this.dataport.itemClass !== "0" || this.dataportPanel.getForm().findField('itemClass').getValue() !== "0",
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport.incremental_export.tooltip')
                            }
                        },
                        {
                            xtype: 'textfield',
                            name: 'archiveFolder',
                            fieldLabel: t('pim.dataport_archiveFolder'),
                            cls: "input_drop_target",
                            value: this.getSourceconfig('archiveFolder'),
                            listeners: {
                                render: function (el) {
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return this.getEl();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                this.setValue(data.path);
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    this.getEl().on('dblclick', function () {
                                        opendxp.helpers.openElement(this.getValue(), 'asset');
                                    }.bind(this));
                                }
                            }
                        }
                    ]
                },
                {
                    xtype: 'panel',
                    collapsible: true,
                    collapsed: true,
                    title: t('pim.dataport_advancedOptions'),
                    cls: 'x-panel-header-light',
                    style: 'border: none',
                    bodyPadding: '10 0 0 10',
                    headerOverCls: 'x-fieldset-header-text-collapsible',
                    titleCollapse: true,
                    fieldDefaults: {
                        labelAlign: 'left',
                        labelWidth: 200
                    },
                    layout: {
                        type: 'vbox',
                        align: 'stretch'
                    },
                    items: [
                        {
                            xtype: 'combo',
                            name: 'mode',
                            fieldLabel: t('pim.dataport_mode'),
                            editable: false,
                            forceSelection: true,
                            store: [
                                ['3', t('pim.dataport_mode.create_and_edit')],
                                ['1', t('pim.dataport_mode.create_only')],
                                ['2', t('pim.dataport_mode.edit_only')]
                            ],
                            value: this.dataport.mode
                        },
                        {
                            xtype: 'checkbox',
                            name: 'compatibilityMode',
                            value: this.dataport.compatibilityMode,
                            fieldLabel: t('pim.compatibilityMode'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.compatibilityMode.tooltip')
                            }
                        },
                        {
                            xtype: 'combo',
                            name: 'javascriptEngine',
                            fieldLabel: t('pim.dataport_javascriptEngine'),
                            hidden: true,
                            editable: false,
                            triggerAction: 'all',
                            store: Ext.create('Ext.data.JsonStore', {
                                model: 'JavascriptEngineModel',
                                proxy: {
                                    type: 'ajax',
                                    url: '/admin/SylphenDataBridge/importconfig/get-javascript-engines',
                                    reader: {
                                        type: 'json',
                                        rootProperty: 'data'
                                    }

                                },
                                autoLoad: true
                            }),
                            valueField: 'id',
                            displayField: 'name',
                            value: this.dataport.javascriptEngine,
                            listeners: {
                                select: function (comp, record, index) {
                                    if (comp.getValue() == "" || comp.getValue() == "&nbsp;") {
                                        comp.setValue(null);
                                    }
                                }
                            }
                        },
                        {
                            xtype: 'checkbox',
                            name: 'optimizeInheritance',
                            value: this.dataport.optimizeInheritance,
                            fieldLabel: t('pim.dataport_optimize_inheritance'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport_optimize_inheritance.tooltip')
                            }
                        },
                        {
                            xtype: 'numberfield',
                            minValue: 0,
                            decimalPrecision: 0,
                            name: 'parallelProcesses',
                            value: this.dataport.parallelProcesses,
                            fieldLabel: t('pim.dataport_parallel_processes'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport_parallel_processes.tooltip')
                            }
                        },
                        {
                            xtype: 'checkbox',
                            name: 'skipVersioning',
                            fieldLabel: t('pim.dataport_skip_versioning'),
                            value: this.dataport.skipVersioning,
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport_skip_versioning.tooltip')
                            },
                            hidden: true
                        }, {
                            xtype: 'textfield',
                            name: 'idPrefix',
                            value: this.dataport.idPrefix,
                            fieldLabel: t('pim.dataport_idprefix'),
                            allowBlank: true,
                            hidden: true
                        }, {
                            xtype: 'textfield',
                            name: 'categoryClass',
                            value: this.dataport.categoryClass,
                            fieldLabel: t('pim.dataport_categoryclass'),
                            allowBlank: true,
                            hidden: true
                        }, {
                            xtype: 'textfield',
                            name: 'fieldnameProducts',
                            value: this.dataport.fieldnameProducts,
                            fieldLabel: t('pim.dataport_fieldnameProducts'),
                            allowBlank: true,
                            hidden: true
                        },
                        {
                            xtype: 'tagfield',
                            name: 'error_recipients[]',
                            fieldLabel: t('pim.dataport.send_error_notification'),
                            store: Ext.create('Ext.data.JsonStore', {
                                proxy: {
                                    type: 'ajax',
                                    url: '/admin/SylphenDataBridge/importconfig/get-recipients/' + this.dataport.id,
                                    fields: ['name', 'userId'],
                                    reader: {
                                        type: 'json',
                                        rootProperty: 'data'
                                    }

                                },
                                autoLoad: true
                            }),
                            value: this.dataport.errorRecipients,
                            valueField: 'userId',
                            displayField: 'name',
                            filterPickList: true,
                            forceSelection: true,
                            queryMode: 'local',
                            anyMatch: true
                        }
                    ]
                }
            ]
        });

        this.sourceConfigPanel.add(this.sourceConfigBasePanel, this.sourceConfigRawitemPanel);

        opendxp.layout.refresh();
    },

    renderPimcoreSourceConfigPanel: function () {
        var additionalColumns = this.getColumnConfigForSourcetype('pimcore');

        // Rohdatenfeldkonfiguration
        if (typeof PimcoreImportconfigModel == 'undefined') {
            var readerFields = [
                {name: 'fieldNo', allowBlank: false, type: 'integer'},
                { name: 'sort', allowBlank: false },
                {name: 'name', allowBlank: false}
            ];

            Ext.each(additionalColumns, function (column) {
                readerFields.push({name: column.dataIndex});
            });

            readerFields.push({name: 'data1', persist: false});

            Ext.define('PimcoreImportconfigModel', {
                extend: 'Ext.data.Model',
                fields: readerFields,
                idProperty: 'fieldNo',
                root: 'fields'
            });
        }

        var store = new Ext.data.JsonStore({
            model: 'PimcoreImportconfigModel',
            proxy: {
                type: 'ajax',
                url: '/admin/SylphenDataBridge/importconfig/get-rawitemfield-config/' + this.dataport.id,
                reader: {
                    type: 'json',
                    rootProperty: 'fields',
                    messageProperty: 'errorMessage'
                }

            },
            listeners: {
                load: function () {
                    Ext.Ajax.request({
                        url: '/admin/SylphenDataBridge/importconfig/get-demo-data',
                        params: {
                            dataportId: this.dataportId
                        },
                        success: function (response) {
                            response = Ext.decode(response.responseText);

                            var store = this.sourceConfigRawitemPanel.getStore();
                            Ext.each(response.data, function (value) {
                                var record = store.findRecord('fieldNo', value.fieldNo, 0, false, true, true)

                                if (record) {
                                    record.set('data1', value.value);
                                }
                            }.bind(this));

                            this.sourceConfigRawitemPanel.getView().refresh();
                        }.bind(this)
                    });
                }.bind(this)
            },
            autoLoad: true
        });

        var sourceDataClassStore = Ext.create('Ext.data.ChainedStore', {
            source: Ext.data.StoreManager.lookup('pimcoreClassesStore'),
            filters: [
                function (record) {
                    return record.get('id') !== "0";
                }
            ]
        });

        var conditionStore = Ext.create('Ext.data.JsonStore', {
            fields: ['value'],
            autoload: true,
            proxy: {
                type: 'ajax',
                url: '/admin/SylphenDataBridge/importconfig/suggest_condition',
                reader: {
                    type: 'json',
                    rootProperty: 'items'
                }
            },
            listeners: {
                beforeload: function (store, operation) {
                    var el = conditionField.inputEl.dom;
                    var rng, cursorPosition = -1;
                    if (typeof el.selectionEnd == "number") {
                        cursorPosition = el.selectionEnd;
                    } else if (document.selection && el.createTextRange) {
                        rng = document.selection.createRange();
                        rng.collapse(true);
                        rng.moveStart("character", -el.value.length);
                        cursorPosition = rng.text.length;
                    }

                    var sourceClass = this.sourceConfigBasePanel.getForm().findField('sourceClass').getValue();

                    operation.setParams({
                        condition: conditionField.getValue().replaceAll(/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]/g, ''),
                        cursorPosition: cursorPosition,
                        sourceClass: sourceClass
                    });

                    return true;
                }.bind(this)
            }
        });

        var conditionField = new Ext.form.field.ComboBox({
            name: 'file',
            fieldLabel: t('filter_condition'),
            allowBlank: true,
            hideTrigger: true,
            typeAhead: true,
            minChars: 0,
            tpl: Ext.create('Ext.XTemplate',
              '<tpl for=".">',
              '<li class="x-boundlist-item">{label}',
              '</li>',
              '</tpl>'
            ),
            grow: true,
            growMin: 30,
            growAppend: '',
            displayField: 'value',
            valueField: 'value',
            triggerAction: 'all',
            store: conditionStore,
            autoSelect: false,
            value: this.getSourceconfig('file'),
            listeners: {
                select: function(combo, record) {
                    conditionStore.on('load', function() {
                        combo.expand();
                    }, {
                        single: true
                    });
                    conditionStore.load();
                },
                render: function (cmp) {
                    Ext.get(cmp.getInputId()).on('mousedown', function (e) {
                        e.stopPropagation();
                    });
                },
                blur: function() {
                    conditionField.collapse();
                },
                change: function(conditionField, newValue) {
                    if(typeof newValue === "string" && newValue.match(/order by[^)]+$/i)) {
                        opendxp.helpers.showNotification(t("error"), t('pim.dataport.order_by_in_condition'), "error");
                    }
                }
            }
        });

        // Panel für Importkonfiguration
        this.sourceConfigBasePanel = new Ext.form.FormPanel({
            padding: 5,
            layout: {
                type: 'vbox',
                align: 'stretch'
            },
            fieldDefaults: {
                labelAlign: 'left',
                labelWidth: 200
            },
            items: [
                {
                    xtype: 'panel',
                    title: t('settings') + ': ' + this.dataportPanel.getForm().findField('sourcetype').getStore().findRecord("code", this.dataportPanel.getForm().findField('sourcetype').getValue()).get("label"),
                    layout: {
                        type: 'vbox',
                        align: 'stretch'
                    },
                    bodyPadding: '10 0 0 10',
                    items: [
                        {
                            xtype: 'combo',
                            name: 'sourceClass',
                            fieldLabel: t('pim.dataport_sourceClass'),
                            anyMatch: true,
                            editable: true,
                            forceSelection: true,
                            valueField: 'id',
                            displayField: 'name',
                            minChars: 0,
                            queryMode: 'local',
                            store: sourceDataClassStore,
                            value: this.getSourceconfig('sourceClass') || this.dataportPanel.getForm().findField('itemClass').getValue(),
                            listConfig: {
                                tpl: [
                                    '<tpl for=".">',
                                    '{[xindex === 1 || parent[xindex - 2].group !== values.group ? "<div style=\'padding: 5px 10px\'>"+values.group+"</div>" : ""]}',
                                    '<div role="option" class="x-boundlist-item">&nbsp;&nbsp;{name}</div>',
                                    '</tpl>'
                                ]
                            },
                            listeners: {
                                change: function() {
                                    var sourceClass = this.sourceConfigBasePanel.getForm().findField('sourceClass').getValue();
                                    var sourceClassRecord = sourceDataClassStore.getById(sourceClass);

                                    var inheritanceEnabledField = this.sourceConfigBasePanel.getForm().findField('inheritanceEnabled');
                                    if (sourceClassRecord !== null && sourceClassRecord.get('supportsInheritance') && inheritanceEnabledField.isHidden()) {
                                        inheritanceEnabledField.show();
                                        inheritanceEnabledField.setValue(this.dataportPanel.getForm().findField('itemClass').getValue() === "0");
                                    } else {
                                        this.sourceConfigBasePanel.getForm().findField('inheritanceEnabled').hide();
                                    }

                                    if(this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Document\\Page" || sourceClass === "OpenDxp\\Model\\Document\\Page") {
                                        this.sourceConfigBasePanel.getForm().findField('masterDocument').show();
                                    } else {
                                        this.sourceConfigBasePanel.getForm().findField('masterDocument').hide();
                                    }
                                }.bind(this),
                                render: function () {
                                    var sourceClass = this.sourceConfigBasePanel.getForm().findField('sourceClass').getValue();
                                    var sourceClassRecord = sourceDataClassStore.getById(sourceClass);
                                    var inheritanceEnabledField = this.sourceConfigBasePanel.getForm().findField('inheritanceEnabled');
                                    if (sourceClassRecord !== null && sourceClassRecord.get('supportsInheritance')) {
                                        inheritanceEnabledField.show();
                                    } else {
                                        inheritanceEnabledField.hide();
                                    }
                                }.bind(this),
                                focus: function (combo) {
                                    setTimeout(function () {
                                        if (!combo.isExpanded) {
                                            combo.expand();
                                        }
                                    }, 100);
                                }
                            }
                        },
                        {
                            xtype: 'checkbox',
                            name: 'inheritanceEnabled',
                            fieldLabel: t('enable_inheritance'),
                            value: this.getSourceconfig('inheritanceEnabled')
                        },
                        conditionField
                    ]
                },
                {
                    xtype: 'panel',
                    itemId: 'targetSettings',
                    title: t('settings') + ': ' +((this.dataport.itemClass === "0" || this.dataportPanel.getForm().findField('itemClass').getValue() === "0") ? t('export') : t('import')),
                    layout: {
                        type: 'vbox',
                        align: 'stretch'
                    },
                    bodyPadding: '10 0 0 10',
                    items: [
                        {
                            xtype: 'textfield',
                            name: 'assetFolder',
                            hidden: this.dataport.itemClass === "0" || this.dataportPanel.getForm().findField('itemClass').getValue() === "0",
                            fieldLabel: t('pim.dataport_assetfolder'),
                            cls: "input_drop_target",
                            inputAttrTpl: 'data-qtip="' + t('pim.dataport.folder.pimcore_or_filesystem') + '"',
                            listeners: {
                                render: function (el) {
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return this.getEl();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                this.setValue(data.path);
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    this.getEl().on('dblclick', function () {
                                        opendxp.helpers.openElement(this.getValue(), 'asset');
                                    }.bind(this));
                                }
                            },
                            value: this.dataport.assetFolder
                        },
                        {
                            xtype: 'textfield',
                            name: 'itemFolder',
                            hidden: this.dataport.itemClass === "OpenDxp\\Model\\Asset" || this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Asset" || this.dataport.itemClass === "0" || this.dataportPanel.getForm().findField('itemClass').getValue() === "0" || (this.sourceConfigBasePanel && this.sourceConfigBasePanel.getForm().findField('mode').getValue() == '2'),
                            fieldLabel: t('pim.dataport_targetfolder'),
                            cls: "input_drop_target",
                            inputAttrTpl: 'data-qtip="' + t('pim.dataport.folder.pimcore_or_filesystem') + '"',
                            value: this.dataport.itemFolder,
                            listeners: {
                                render: function (el) {
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return e.getTarget();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "object" || data.elementType == "document") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "object" || data.elementType == "document") {
                                                target.value = data.path;
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    el.getEl().on('dblclick', function () {
                                        var elementType = 'object';
                                        if (this.dataport.itemClass === "OpenDxp\\Model\\Asset" || this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Asset") {
                                            elementType = 'asset';
                                        } else if (this.dataport.itemClass === "OpenDxp\\Model\\Document\\Page" || this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Document\\Page") {
                                            elementType = 'document';
                                        }
                                        opendxp.helpers.openElement(el.getValue(), elementType);
                                    }.bind(this));
                                }.bind(this)
                            }
                        },
                        {
                            xtype: 'textfield',
                            name: 'masterDocument',
                            hidden: (this.dataport.itemClass !== "OpenDxp\\Model\\Document\\Page" || this.dataportPanel.getForm().findField('itemClass').getValue() !== "OpenDxp\\Model\\Document\\Page") && this.getSourceconfig('sourceClass') !== "OpenDxp\\Model\\Document\\Page",
                            fieldLabel: t('content_master_document'),
                            cls: "input_drop_target",
                            value: this.dataport.masterDocument,
                            listeners: {
                                render: function (el) {
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return this.getEl();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "document") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "document") {
                                                this.setValue(data.path);
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    this.getEl().on('dblclick', function () {
                                        opendxp.helpers.openElement(this.getValue(), 'document');
                                    }.bind(this));
                                }
                            }
                        },
                        {
                            xtype: 'checkbox',
                            name: 'autoImport',
                            fieldLabel: t('pim.dataport.auto-import'),
                            value: this.getSourceconfig('autoImport'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': (this.dataport.itemClass === "0" || this.dataportPanel.getForm().findField('itemClass').getValue() === "0") ? t('pim.dataport.auto-import.tooltip.export') : t('pim.dataport.auto-import.tooltip')
                            },
                            listeners: {
                                change: function (el, newValue) {
                                    if (!newValue) {
                                        this.sourceConfigBasePanel.getForm().findField('incrementalExport').hide();
                                        this.sourceConfigBasePanel.getForm().findField('draftVersions').hide();
                                    } else {
                                        this.sourceConfigBasePanel.getForm().findField('draftVersions').show();
                                        if (this.dataportPanel.getForm().findField('itemClass').getValue() === '0') {
                                            this.sourceConfigBasePanel.getForm().findField('incrementalExport').show();
                                        }
                                    }
                                }.bind(this)
                            }
                        },
                        {
                            xtype: 'checkbox',
                            name: 'incrementalExport',
                            fieldLabel: t('pim.dataport.incremental_export'),
                            value: this.getSourceconfig('incrementalExport'),
                            hidden: this.dataport.itemClass !== "0" || this.dataportPanel.getForm().findField('itemClass').getValue() !== "0" || !this.getSourceconfig('autoImport'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport.incremental_export.tooltip')
                            }
                        },
                        {
                            xtype: 'checkbox',
                            name: 'draftVersions',
                            fieldLabel: t('pim.dataport.incremental_export.draft_versions'),
                            value: this.getSourceconfig('draftVersions'),
                            hidden: !this.getSourceconfig('autoImport'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport.incremental_export.draft_versions.tooltip')
                            }
                        }]
                },
                {
                    xtype: 'panel',
                    collapsible: true,
                    collapsed: true,
                    title: t('pim.dataport_advancedOptions'),
                    cls: 'x-panel-header-light',
                    style: 'border: none',
                    bodyPadding: '10 0 0 10',
                    headerOverCls: 'x-fieldset-header-text-collapsible',
                    titleCollapse: true,
                    fieldDefaults: {
                        labelAlign: 'left',
                        labelWidth: 200
                    },
                    layout: {
                        type: 'vbox',
                        align: 'stretch'
                    },
                    items: [
                        {
                            xtype: 'combo',
                            name: 'mode',
                            fieldLabel: t('pim.dataport_mode'),
                            editable: false,
                            forceSelection: true,
                            store: [
                                ['3', t('pim.dataport_mode.create_and_edit')],
                                ['1', t('pim.dataport_mode.create_only')],
                                ['2', t('pim.dataport_mode.edit_only')]
                            ],
                            value: this.dataport.mode
                        },
                        {
                            xtype: 'checkbox',
                            name: 'compatibilityMode',
                            value: this.dataport.compatibilityMode,
                            fieldLabel: t('pim.compatibilityMode'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.compatibilityMode.tooltip')
                            }
                        },
                        {
                            xtype: 'combo',
                            name: 'javascriptEngine',
                            fieldLabel: t('pim.dataport_javascriptEngine'),
                            hidden: true,
                            editable: false,
                            triggerAction: 'all',
                            store: Ext.create('Ext.data.JsonStore', {
                                model: 'JavascriptEngineModel',
                                proxy: {
                                    type: 'ajax',
                                    url: '/admin/SylphenDataBridge/importconfig/get-javascript-engines',
                                    reader: {
                                        type: 'json',
                                        rootProperty: 'data'
                                    }

                                },
                                autoLoad: true
                            }),
                            valueField: 'id',
                            displayField: 'name',
                            value: this.dataport.javascriptEngine,
                            listeners: {
                                select: function (comp, record, index) {
                                    if (comp.getValue() == "" || comp.getValue() == "&nbsp;") {
                                        comp.setValue(null);
                                    }
                                }
                            }
                        },
                        {
                            xtype: 'checkbox',
                            name: 'optimizeInheritance',
                            value: this.dataport.optimizeInheritance,
                            fieldLabel: t('pim.dataport_optimize_inheritance'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport_optimize_inheritance.tooltip')
                            }
                        },
                        {
                            xtype: 'numberfield',
                            minValue: 0,
                            decimalPrecision: 0,
                            name: 'parallelProcesses',
                            value: this.dataport.parallelProcesses,
                            fieldLabel: t('pim.dataport_parallel_processes'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport_parallel_processes.tooltip')
                            }
                        },
                        {
                            xtype: 'checkbox',
                            name: 'skipVersioning',
                            fieldLabel: t('pim.dataport_skip_versioning'),
                            value: this.dataport.skipVersioning,
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport_skip_versioning.tooltip')
                            },
                            hidden: true
                        }, {
                            xtype: 'textfield',
                            name: 'idPrefix',
                            value: this.dataport.idPrefix,
                            fieldLabel: t('pim.dataport_idprefix'),
                            allowBlank: true,
                            hidden: true
                        }, {
                            xtype: 'textfield',
                            name: 'categoryClass',
                            value: this.dataport.categoryClass,
                            fieldLabel: t('pim.dataport_categoryclass'),
                            allowBlank: true,
                            hidden: true
                        }, {
                            xtype: 'textfield',
                            name: 'fieldnameProducts',
                            value: this.dataport.fieldnameProducts,
                            fieldLabel: t('pim.dataport_fieldnameProducts'),
                            allowBlank: true,
                            hidden: true
                        },
                        {
                            xtype: 'tagfield',
                            name: 'error_recipients[]',
                            fieldLabel: t('pim.dataport.send_error_notification'),
                            store: Ext.create('Ext.data.JsonStore', {
                                proxy: {
                                    type: 'ajax',
                                    url: '/admin/SylphenDataBridge/importconfig/get-recipients/' + this.dataport.id,
                                    fields: ['name', 'userId'],
                                    reader: {
                                        type: 'json',
                                        rootProperty: 'data'
                                    }

                                },
                                autoLoad: true
                            }),
                            value: this.dataport.errorRecipients,
                            valueField: 'userId',
                            displayField: 'name',
                            filterPickList: true,
                            forceSelection: true,
                            queryMode: 'local',
                            anyMatch: true
                        }
                    ]
                }

            ]
        });

        var columnConfig = [];
        columnConfig.push({
            header: "#",
            width: 50,
            dataIndex: 'fieldNo',
            renderer: function (value, metaData, record) {
                if (record.get('sort') === 'DESC') {
                    metaData.tdAttr = 'data-qtip="' + t('pim.dataport-fields.sorting-direction.desc') + '"';
                    value += ' <img src="/bundles/opendxpadmin/img/flat-color-icons/alphabetical_sorting_za.svg" height="18" width="18" style="height:18px;vertical-align:middle">';
                } else {
                    metaData.tdAttr = 'data-qtip="' + t('pim.dataport-fields.sorting-direction.asc') + '"';
                }
                return value;
            }
        });
        columnConfig.push({
            header: t('pim.dataport-fields-name'),
            width: 'auto',
            dataIndex: 'name',
            flex: 1,
            renderer: function (value, metaData, record) {
                if (!value) {
                    return '';
                }

                var mappingRecords = this.mappingPanel.getStore().queryBy(function () { return true; }).getRange();
                var isMapped = false;
                var minOneFieldIsMapped = false;
                Ext.each(mappingRecords, function (mappingRecord) {
                    if (mappingRecord.get('field')) {
                        minOneFieldIsMapped = true;
                    }

                    if (record.get('fieldNo') === mappingRecord.get('field')) {
                        isMapped = true;
                        return false;
                    }

                    var mappingRecordSettings = mappingRecord.get('settings');
                    if (mappingRecordSettings.calculation) {
                        minOneFieldIsMapped = true;
                        if (this.dataport.javascriptEngine === 'php' && mappingRecordSettings.calculation.indexOf('$params[\'rawItemData\'][\'' + value + '\']') > -1) {
                            isMapped = true;
                            return false;
                        } else if (this.dataport.javascriptEngine === 'v8js' && (mappingRecordSettings.calculation.indexOf('params[\'rawItemData\'][\'' + value + '\']') > -1 || mappingRecordSettings.calculation.indexOf('params.rawItemData.' + value) > -1)) {
                            isMapped = true;
                            return false;
                        }
                    }
                }.bind(this));

                if (!isMapped && minOneFieldIsMapped) {
                    metaData.tdAttr = 'data-qtip="' + t('pim.mapping.variables.variable.not_in_use') + '"';
                    return '<s>' + Ext.util.Format.htmlEncode(value) + '</s>';
                }
                return Ext.util.Format.htmlEncode(value);
            }.bind(this),
            editor: {
                allowBlank: false,
                xtype: 'textfield',
                handleMouseEvents: true,
                listeners: {
                    render: function (cmp) {
                        Ext.get(cmp.getInputId()).on('mousedown', function (e) {
                            e.stopPropagation();
                        });
                    }
                }
            }
        });

        Ext.each(additionalColumns, function (column) {
            columnConfig.push(column);
        });

        columnConfig.push({
            header: t('pim.dataport-fields-ex1'),
            dataIndex: 'data1',
            flex: 1,
            renderer: function (value, metaData, record) {
                if (typeof value === 'undefined') {
                    return '';
                }
                return Ext.util.Format.htmlEncode(value);
            }
        });

        var showHideExportKeyColumn = function() {
            var column = this.sourceConfigRawitemPanel.down('[dataIndex=exportKey]');
            if(column) {
                if(this.dataportPanel.getForm().findField('itemClass').getValue() == '0') {
                    column.show();
                } else {
                    column.hide();
                }
            }
        }.bind(this);

        this.dataportPanel.getForm().findField('itemClass').on('change', function(sourceClass) {
            showHideExportKeyColumn();
        }.bind(this));

        this.sourceConfigRawitemPanel = Ext.create('Ext.grid.Panel', {
            title: '',
            store: store,
            columns: {
                defaults: {
                    menuDisabled : true,
                    sortable: false
                },
                items: columnConfig
            },
            flex: 1,
            border: false,
            frame: false,
            autoScroll: true,
            layout: 'fit',
            minHeight:300,
            selModel: {
                selType: 'rowmodel',
                mode: 'MULTI',
                ignoreRightMouseSelection: true
            },
            multiSelect: true,
            dockedItems: [{
                xtype: 'toolbar',
                dock: 'top',
                items: [
                {
                    xtype: 'label',
                    html: t('pim.rawdatafields'),
                    cls: 'x-panel-header-title-default x-panel-header-default',
                    style: 'border:none;'
                },{
                    text: t('add'),
                    handler: this.onAddDataport.bind(this),
                    iconCls: "opendxp_icon_add"
                },
                '-',
                {
                    text: t('delete'),
                    handler: function () {
                        this.onDeleteDataport();
                    }.bind(this),
                    iconCls: "opendxp_icon_delete"
                },
                '-',
                {
                    text: t('pim.rawdatafields.object_tree'),
                    iconCls: "opendxp_icon_treeSelect",
                    handler: function () {
                        var columnConfig = {
                            language: 'default',
                            classid: this.sourceConfigBasePanel.getForm().findField('sourceClass').getValue(),
                            selectedGridColumns: []
                        };

                        var dialog = new opendxp.object.helpers.gridConfigDialog(columnConfig,
                            function (data, settings, save) {
                                Ext.each(data.columns, function (field) {
                                    if(field.isOperator) {
                                        opendxp.helpers.showNotification(t("error"), 'Operators are currently not supported', "error", 'Please write to info@sylphen.com if you are interested in support for OpenDxp\'s grid operators in Data Bridge.');
                                        return true;
                                    }

                                    if(field.key.indexOf('~') > -1) {
                                        var parts = field.key.split('~');
                                        field.key = 'brick#'+ parts[0]+':'+parts[1];
                                    }

                                    if(dialog.languageField.getValue() !== 'default') {
                                        field.key += '#'+dialog.languageField.getValue();
                                    }

                                    if (store.findExact('name', field.key) === -1) {
                                        this.onAddDataport();
                                        var record = this.sourceConfigRawitemPanel.getSelectionModel().getSelection()[0];
                                        record.set({
                                            name: field.key,
                                            parameters: field.key,
                                            exportKey: false
                                        });
                                    }
                                }.bind(this));
                            }.bind(this),
                            null, false, {},
                            {
                                allowPreview: true,
                                classId: this.sourceConfigBasePanel.getForm().findField('sourceClass').getValue(),
                                objectId: null
                            }
                        );
                    }.bind(this)
                },
                {
                    text: t('pim.rawdatafields.bulk_edit'),
                    iconCls: "opendxp_icon_edit",
                    handler: function () {
                        var displayField = {
                            xtype: "displayfield",
                            region: "north",
                            hideLabel: true,
                            border: false,
                            value: t('pim.rawdatafields.bulk_edit.description') + ':<br>' + Ext.Array.filter(Ext.Array.map(columnConfig, function (column) {
                                return column.dataIndex;
                            }), function (dataIndex) {
                                return dataIndex !== 'data1';
                            }).join(',')
                        };

                        var data = [];
                        store.each(function (rec) {
                            var row = [];
                            Ext.Array.each(columnConfig, function (column) {
                                if (column.dataIndex === 'data1') {
                                    return true;
                                }

                                var value = rec.get(column.dataIndex);
                                if(typeof value === "boolean") {
                                    value = value ? 1 : 0;
                                } else if(value === "false") {
                                    value = 0;
                                } else if (value === "true") {
                                    value = 1;
                                } else if(column.dataIndex === 'exportKey' && value === null) {
                                    value = 0;
                                }

                                row.push(value);
                            });
                            data.push(row);
                        });

                        data = Ext.util.CSV.encode(data);

                        var textarea = new Ext.form.TextArea({
                            region: "center",
                            border: false,
                            value: data
                        });

                        var bulkEditWindow = new Ext.Window({
                            width: 800,
                            height: 500,
                            title: t('pim.rawdatafields.bulk_edit'),
                            iconCls: "opendxp_icon_edit",
                            layout: "fit",
                            modal: true,
                            resizable: true,
                            items: [new Ext.Panel({
                                layout: "border",
                                padding: '0 10',
                                items: [displayField, textarea]
                            })],
                            buttons: [
                                {
                                    text: t('apply'),
                                    iconCls: "opendxp_icon_save",
                                    handler: function () {
                                        store.removeAll();
                                        var content = textarea.getValue();
                                        if (content.length > 0) {
                                            var csvData = Ext.util.CSV.decode(content);

                                            for (var i = 0; i < csvData.length; i++) {
                                                var row = csvData[i];

                                                this.onAddDataport();
                                                var record = this.sourceConfigRawitemPanel.getSelectionModel().getSelection()[0];

                                                Ext.Array.each(columnConfig, function (column, index) {
                                                    if (column.dataIndex === 'exportKey' && row[index] == 0) {
                                                        row[index] = false;
                                                    }
                                                    record.set(column.dataIndex, row[index])
                                                });
                                            }
                                        }

                                        bulkEditWindow.close();
                                    }.bind(this)
                                },
                                {
                                    text: t('cancel'),
                                    iconCls: "opendxp_icon_empty",
                                    handler: function () {
                                        bulkEditWindow.close();
                                    }
                                }
                            ]
                        }).show();
                    }.bind(this)
                },
                '->',
                {
                    text: t('pim.dataport.auto_create'),
                    handler: function() {
                        Ext.Ajax.request({
                            url: '/admin/SylphenDataBridge/importconfig/auto-create-rawdata-fields',
                            method: 'post',
                            params: {
                                sourceType: this.dataportPanel.getForm().findField('sourcetype').getValue(),
                                file: this.sourceConfigBasePanel.getForm().findField('file').getValue(),
                                sourceClass: this.sourceConfigBasePanel.getForm().findField('sourceClass').getValue(),
                                masterDocument: this.sourceConfigBasePanel.getForm().findField('masterDocument').getValue(),
                                dataportId: this.dataportId
                            },
                            success: function (response) {
                                response = Ext.decode(response.responseText);

                                if(typeof response.config !== 'undefined') {
                                    var store = this.sourceConfigRawitemPanel.getStore();
                                    Ext.each(response.config.fields, function(field) {
                                        if(store.findExact('name', field.name) === -1) {
                                            this.onAddDataport();
                                            var record = this.sourceConfigRawitemPanel.getSelectionModel().getSelection()[0];
                                            record.set({
                                                name: field.name,
                                                parameters: field.parameters,
                                                exportKey: field.exportKey,
                                                sort: 'ASC'
                                            });
                                        }
                                    }.bind(this));
                                } else {
                                    Ext.MessageBox.alert(t('error'), response.msg);
                                }
                            }.bind(this)
                        });
                    }.bind(this),
                    iconCls: "opendxp_icon_clear_cache"
                }]
            }],

            listeners: {
                beforeedit: function (editor, context) {
                    context.grid.getView().getPlugin('gridviewdragdrop').disable();
                },
                canceledit: function (editor, context) {
                    context.grid.getView().getPlugin('gridviewdragdrop').enable();
                },
                edit: function (editor, e) {
                    if (e.field === 'parameters') {
                        e.value = e.value.replace(new RegExp(":+$"), "");
                        e.record.set('parameters', e.value);
                    }
                    if (!e.record.get('name') && e.field === 'parameters' && e.value !== '') {
                        var isValid = true;

                        store.each(function (record) {
                            if (record.get('name') === e.value && record.get('fieldNo') !== e.record.get('fieldNo')) {
                                isValid = false;
                                return false;
                            }
                        });

                        if(isValid) {
                            e.record.set('name', e.value);
                        }
                    }

                    this.onAddDataport(false);

                    e.grid.getView().getPlugin('gridviewdragdrop').enable();
                }.bind(this),
                validateedit: function (editor, e) {
                    var isValid = true;

                    if (e.field === 'name') {
                        store.each(function (record) {
                            if (record.get(e.field) === e.value && e.record.get('fieldNo') !== record.get('fieldNo')) {
                                isValid = false;
                                return false;
                            }
                        });
                    }

                    if (!isValid) {
                        opendxp.helpers.showNotification(t("error"), t('pim.dataport-fields.duplicate'), "error");
                    }

                    return isValid;
                }.bind(this),
                celldblclick: function (grid, cell, cellIndex, record) {
                    if(columnConfig[cellIndex].dataIndex === 'data1') {
                        opendxp.helpers.copyStringToClipboard(record.get('data1'));
                        opendxp.helpers.showNotification(t("info"), t('pim.mapping.copied_to_clipboard_mapping'), 'info');
                    }
                },
                cellclick: function (grid, tdElement, cellIndex, record) {
                    if (columnConfig[cellIndex].dataIndex === 'fieldNo') {
                        if (record.get('sort') === 'ASC') {
                            record.set('sort', 'DESC');
                        } else {
                            record.set('sort', 'ASC');
                        }
                    }
                },
                render: showHideExportKeyColumn,
                itemcontextmenu: function(view, record, item, index, e) {
                    var menu = new Ext.menu.Menu();

                    var selectedRows = this.sourceConfigRawitemPanel.getSelectionModel().getSelection();
                    var clickedItemIsSelected = false;
                    if (selectedRows.length > 0) {
                        Ext.each(selectedRows, function (selectedRecord, index) {
                            if (record.get('fieldNo') === selectedRecord.get('fieldNo')) {
                                clickedItemIsSelected = true;
                                return false;
                            }
                        });
                    } else {
                        clickedItemIsSelected = true;
                    }

                    var records = [];
                    if (clickedItemIsSelected) {
                        records = selectedRows.reverse();
                    }

                    if (records.length === 0) {
                        records = selectedRows.length > 0 ? selectedRows : [record];
                    }

                    menu.add(new Ext.menu.Item({
                        text: t('pim.dataport.move-to-top'),
                        icon: "/bundles/opendxpadmin/img/flat-color-icons/up.svg",
                        handler: function (menuItem, e) {
                            Ext.each(records, function (record) {
                                store.data.remove(record);
                                store.data.insert(0, record);
                            });
                        }.bind(this),
                    }));

                    if (!clickedItemIsSelected) {
                        menu.add(new Ext.menu.Item({
                            text: t('pim.dataport.move-to-selection').replace('%s', selectedRows[selectedRows.length - 1].get('name')),
                            iconCls: "opendxp_icon_table_row",
                            handler: function (menuItem, e) {
                                store.data.remove(record);
                                store.data.insert(store.indexOf(selectedRows[selectedRows.length - 1]) + 1, record);
                            }.bind(this),
                        }));
                    }

                    if (record.get('sort') === null || record.get('sort') === 'ASC') {
                        menu.add(new Ext.menu.Item({
                            text: t('pim.dataport.sort-descending'),
                            icon: "/bundles/opendxpadmin/img/flat-color-icons/alphabetical_sorting_za.svg",
                            handler: function (menuItem, e) {
                                Ext.each(records, function (record) {
                                    record.set('sort', 'DESC');
                                });
                            }.bind(this),
                        }));
                    } else if (record.get('sort') === 'DESC') {
                        menu.add(new Ext.menu.Item({
                            text: t('pim.dataport.sort-ascending'),
                            icon: "/bundles/opendxpadmin/img/flat-color-icons/alphabetical_sorting_az.svg",
                            handler: function (menuItem, e) {
                                Ext.each(records, function (record) {
                                    record.set('sort', 'ASC');
                                });
                            }.bind(this),
                        }));
                    }

                    menu.add(new Ext.menu.Item({
                        text: t('add'),
                        iconCls: "opendxp_icon_add",
                        handler: this.onAddDataport.bind(this),
                    }));

                    menu.add(new Ext.menu.Item({
                        text: t('copy'),
                        iconCls: "opendxp_icon_copy",
                        handler: function () {
                            Ext.each(records, function (record) {
                                if(selectedRows.length === 1) {
                                    this.sourceConfigRawitemPanel.getSelectionModel().select(record);
                                } else {
                                    this.sourceConfigRawitemPanel.getSelectionModel().deselectAll();
                                }

                                var addedRecord = this.onAddDataport();
                                var name = record.get('name').replace(/(.*)(\d+)$/, function (input, namePrefix, trailingDigits) {
                                    return namePrefix + (parseInt(trailingDigits, 10) + 1);
                                });

                                addedRecord.set('name', name);

                                var column = record.get('parameters');
                                addedRecord.set('parameters', column);
                            }.bind(this));
                        }.bind(this)
                    }));

                    menu.add(new Ext.menu.Item({
                        text: t('delete'),
                        iconCls: "opendxp_icon_delete",
                        handler: function () {
                            this.onDeleteDataport(record);
                        }.bind(this)
                    }));

                    menu.showAt(e.getXY());

                    e.stopEvent();
                }.bind(this)
            },
            plugins: [Ext.create('Ext.grid.plugin.CellEditing', { clicksToEdit: 2 })],
            viewConfig: {
                plugins: {
                    ptype: 'gridviewdragdrop',
                    pluginId: 'gridviewdragdrop',
                    dragText: t('pim.dataport.change-order-hint')
                }
            }
        });

        this.sourceConfigPanel.add(this.sourceConfigBasePanel, this.sourceConfigRawitemPanel);

        opendxp.layout.refresh();
    },

    renderReportSourceConfigPanel: function () {
        var additionalColumns = this.getColumnConfigForSourcetype('report');

        // Rohdatenfeldkonfiguration
        if (typeof ReportImportconfigModel == 'undefined') {
            var readerFields = [
                { name: 'fieldNo', allowBlank: false, type: 'integer' },
                { name: 'sort', allowBlank: false },
                { name: 'name', allowBlank: false }
            ];

            Ext.each(additionalColumns, function (column) {
                readerFields.push({ name: column.dataIndex });
            });

            readerFields.push({ name: 'data1', persist: false });

            Ext.define('ReportImportconfigModel', {
                  extend: 'Ext.data.Model',
                  fields: readerFields,
                  idProperty: 'fieldNo',
                  root: 'fields'
              }
            );
        }

        var store = new Ext.data.JsonStore({
            model: 'ReportImportconfigModel',
            proxy: {
                type: 'ajax',
                url: '/admin/SylphenDataBridge/importconfig/get-rawitemfield-config/' + this.dataport.id,
                reader: {
                    type: 'json',
                    rootProperty: 'fields',
                    messageProperty: 'errorMessage'
                }

            },
            listeners: {
                load: function () {
                    Ext.Ajax.request({
                        url: '/admin/SylphenDataBridge/importconfig/get-demo-data',
                        params: {
                            dataportId: this.dataportId
                        },
                        success: function (response) {
                            response = Ext.decode(response.responseText);

                            var store = this.sourceConfigRawitemPanel.getStore();
                            Ext.each(response.data, function (value) {
                                var record = store.findRecord('fieldNo', value.fieldNo, 0, false, true, true)

                                if (record) {
                                    record.set('data1', value.value);
                                }
                            }.bind(this));

                            this.sourceConfigRawitemPanel.getView().refresh();
                        }.bind(this)
                    });
                }.bind(this)
            },
            autoLoad: true
        });

        var columnConfig = [];
        columnConfig.push({
            header: "#",
            width: 50,
            dataIndex: 'fieldNo',
            renderer: function (value, metaData, record) {
                if (record.get('sort') === 'DESC') {
                    metaData.tdAttr = 'data-qtip="' + t('pim.dataport-fields.sorting-direction.desc') + '"';
                    value += ' <img src="/bundles/opendxpadmin/img/flat-color-icons/alphabetical_sorting_za.svg" height="18" width="18" style="height:18px;vertical-align:middle">';
                } else {
                    metaData.tdAttr = 'data-qtip="' + t('pim.dataport-fields.sorting-direction.asc') + '"';
                }
                return value;
            }
        });
        columnConfig.push({
            header: t('pim.dataport-fields-name'),
            dataIndex: 'name',
            flex: 1,
            width: 'auto',
            renderer: function (value, metaData, record) {
                if (!value) {
                    return '';
                }

                var mappingRecords = this.mappingPanel.getStore().queryBy(function () { return true; }).getRange();
                var isMapped = false;
                var minOneFieldIsMapped = false;
                Ext.each(mappingRecords, function (mappingRecord) {
                    if (mappingRecord.get('field')) {
                        minOneFieldIsMapped = true;
                    }

                    if (record.get('fieldNo') === mappingRecord.get('field')) {
                        isMapped = true;
                        return false;
                    }

                    var mappingRecordSettings = mappingRecord.get('settings');
                    if (mappingRecordSettings.calculation) {
                        minOneFieldIsMapped = true;
                        if (this.dataport.javascriptEngine === 'php' && mappingRecordSettings.calculation.indexOf('$params[\'rawItemData\'][\'' + value + '\']') > -1) {
                            isMapped = true;
                            return false;
                        } else if (this.dataport.javascriptEngine === 'v8js' && (mappingRecordSettings.calculation.indexOf('params[\'rawItemData\'][\'' + value + '\']') > -1 || mappingRecordSettings.calculation.indexOf('params.rawItemData.' + value) > -1)) {
                            isMapped = true;
                            return false;
                        }
                    }
                }.bind(this));

                if (!isMapped && minOneFieldIsMapped) {
                    metaData.tdAttr = 'data-qtip="' + t('pim.mapping.variables.variable.not_in_use') + '"';
                    return '<s>' + Ext.util.Format.htmlEncode(value) + '</s>';
                }
                return Ext.util.Format.htmlEncode(value);
            }.bind(this),
            editor: {
                xtype: 'textfield',
                allowBlank: false,
                handleMouseEvents: true,
                listeners: {
                    render: function (cmp) {
                        Ext.get(cmp.getInputId()).on('mousedown', function (e) {
                            e.stopPropagation();
                        });
                    }
                }
            }
        });

        Ext.each(additionalColumns, function (column) {
            columnConfig.push(column);
        });

        columnConfig.push({
            header: t('pim.dataport-fields-ex1'),
            dataIndex: 'data1',
            flex: 1,
            renderer: function (value, metaData, record) {
                if (typeof value === 'undefined') {
                    return '';
                }
                return Ext.util.Format.htmlEncode(value);
            }
        });

        this.sourceConfigRawitemPanel = Ext.create('Ext.grid.Panel', {
            title: '',
            store: store,
            columns: {
                defaults: {
                    menuDisabled: true,
                    sortable: false
                },
                items: columnConfig
            },
            flex: 1,
            border: false,
            frame: false,
            autoScroll: true,
            layout: 'fit',
            minHeight: 300,
            selModel: {
                selType: 'rowmodel',
                mode: 'MULTI',
                ignoreRightMouseSelection: true
            },
            multiSelect: true,
            dockedItems: [{
                xtype: 'toolbar',
                dock: 'top',
                items: [
                {
                    xtype: 'label',
                    html: t('pim.rawdatafields'),
                    cls: 'x-panel-header-title-default x-panel-header-default',
                    style: 'border:none;'
                },{
                    text: t('add'),
                    handler: this.onAddDataport.bind(this),
                    iconCls: "opendxp_icon_add"
                },
                    '-',
                    {
                        text: t('delete'),
                        handler: function () {
                            this.onDeleteDataport();
                        }.bind(this),
                        iconCls: "opendxp_icon_delete"
                    },
                    '-',
                    {
                        text: t('pim.rawdatafields.bulk_edit'),
                        iconCls: "opendxp_icon_edit",
                        handler: function () {
                            var displayField = {
                                xtype: "displayfield",
                                region: "north",
                                hideLabel: true,
                                border: false,
                                value: t('pim.rawdatafields.bulk_edit.description') + ':<br>' + Ext.Array.filter(Ext.Array.map(columnConfig, function (column) {
                                    return column.dataIndex;
                                }), function (dataIndex) {
                                    return dataIndex !== 'data1';
                                }).join(',')
                            };

                            var data = [];
                            store.each(function (rec) {
                                var row = [];
                                Ext.Array.each(columnConfig, function (column) {
                                    if (column.dataIndex === 'data1') {
                                        return true;
                                    }
                                    row.push(rec.get(column.dataIndex));
                                });
                                data.push(row);
                            });

                            data = Ext.util.CSV.encode(data);

                            var textarea = new Ext.form.TextArea({
                                region: "center",
                                border: false,
                                value: data
                            });

                            var bulkEditWindow = new Ext.Window({
                                width: 800,
                                height: 500,
                                title: t('pim.rawdatafields.bulk_edit'),
                                iconCls: "opendxp_icon_edit",
                                layout: "fit",
                                modal: true,
                                resizable: true,
                                items: [new Ext.Panel({
                                    layout: "border",
                                    padding: '0 10',
                                    items: [displayField, textarea]
                                })],
                                buttons: [
                                    {
                                        text: t('apply'),
                                        iconCls: "opendxp_icon_save",
                                        handler: function () {
                                            store.removeAll();
                                            var content = textarea.getValue();
                                            if (content.length > 0) {
                                                var csvData = Ext.util.CSV.decode(content);

                                                for (var i = 0; i < csvData.length; i++) {
                                                    var row = csvData[i];

                                                    this.onAddDataport();
                                                    var record = this.sourceConfigRawitemPanel.getSelectionModel().getSelection()[0];

                                                    Ext.Array.each(columnConfig, function (column, index) {
                                                        record.set(column.dataIndex, row[index])
                                                    });
                                                }
                                            }

                                            bulkEditWindow.close();
                                        }.bind(this)
                                    },
                                    {
                                        text: t('cancel'),
                                        iconCls: "opendxp_icon_empty",
                                        handler: function () {
                                            bulkEditWindow.close();
                                        }
                                    }
                                ]
                            }).show();
                        }.bind(this)
                    },
                    '->',
                    {
                        text: t('pim.dataport.auto_create'),
                        handler: function () {
                            Ext.Ajax.request({
                                url: '/admin/SylphenDataBridge/importconfig/auto-create-rawdata-fields',
                                method: 'post',
                                params: {
                                    sourceType: this.dataportPanel.getForm().findField('sourcetype').getValue(),
                                    file: this.sourceConfigBasePanel.getForm().findField('file').getValue(),
                                    dataportId: this.dataportId
                                },
                                success: function (response) {
                                    response = Ext.decode(response.responseText);

                                    if (typeof response.config !== 'undefined') {
                                        var store = this.sourceConfigRawitemPanel.getStore();
                                        Ext.each(response.config.fields, function (field) {
                                            if (store.findExact('name', field.name) === -1 && store.findExact('column', field.name) === -1) {
                                                this.onAddDataport();
                                                var record = this.sourceConfigRawitemPanel.getSelectionModel().getSelection()[0];
                                                record.set({
                                                    name: field.name,
                                                    column: field.column
                                                });
                                            }
                                        }.bind(this));
                                    } else {
                                        Ext.MessageBox.alert(t('error'), response.msg);
                                    }
                                }.bind(this)
                            });
                        }.bind(this),
                        iconCls: "opendxp_icon_clear_cache"
                    }]
            }],

            listeners: {
                beforeedit: function (editor, context) {
                    context.grid.getView().getPlugin('gridviewdragdrop').disable();
                },
                canceledit: function (editor, context) {
                    context.grid.getView().getPlugin('gridviewdragdrop').enable();
                },
                edit: function (editor, e) {
                    if (!e.record.get('name') && e.field === 'column' && e.value !== '') {
                        var isValid = true;

                        store.each(function (record) {
                            if (record.get('name') === e.value && record.get('fieldNo') !== e.record.get('fieldNo')) {
                                isValid = false;
                                return false;
                            }
                        });

                        if (isValid) {
                            e.record.set('name', e.value);
                        }
                    }

                    this.onAddDataport(false);

                    e.grid.getView().getPlugin('gridviewdragdrop').enable();
                }.bind(this),
                validateedit: function (editor, e) {
                    var isValid = true;

                    store.each(function (record) {
                        if (e.value !== '' && record.get(e.field) === e.value && e.record.get('fieldNo') !== record.get('fieldNo')) {
                            isValid = false;
                            return false;
                        }
                    });

                    if (!isValid) {
                        opendxp.helpers.showNotification(t("error"), t('pim.dataport-fields.duplicate'), "error");
                    }

                    return isValid;
                }.bind(this),
                celldblclick: function (grid, cell, cellIndex, record) {
                    if (columnConfig[cellIndex].dataIndex === 'data1') {
                        opendxp.helpers.copyStringToClipboard(record.get('data1'));
                        opendxp.helpers.showNotification(t("info"), t('pim.mapping.copied_to_clipboard_mapping'), 'info');
                    }
                },
                cellclick: function (grid, tdElement, cellIndex, record) {
                    if (columnConfig[cellIndex].dataIndex === 'fieldNo') {
                        if (record.get('sort') === 'ASC') {
                            record.set('sort', 'DESC');
                        } else {
                            record.set('sort', 'ASC');
                        }
                    }
                },
                itemcontextmenu: function (view, record, item, index, e) {
                    var menu = new Ext.menu.Menu();

                    var selectedRows = this.sourceConfigRawitemPanel.getSelectionModel().getSelection();
                    var clickedItemIsSelected = false;
                    if (selectedRows.length > 0) {
                        Ext.each(selectedRows, function (selectedRecord, index) {
                            if (record.get('fieldNo') === selectedRecord.get('fieldNo')) {
                                clickedItemIsSelected = true;
                                return false;
                            }
                        });
                    } else {
                        clickedItemIsSelected = true;
                    }

                    var records = [];
                    if (clickedItemIsSelected) {
                        records = selectedRows.reverse();
                    }

                    if (records.length === 0) {
                        records = selectedRows.length > 0 ? selectedRows : [record];
                    }

                    menu.add(new Ext.menu.Item({
                        text: t('pim.dataport.move-to-top'),
                        icon: "/bundles/opendxpadmin/img/flat-color-icons/up.svg",
                        handler: function (menuItem, e) {
                            Ext.each(records, function (record) {
                                store.data.remove(record);
                                store.data.insert(0, record);
                            });
                        }.bind(this),
                    }));

                    if (!clickedItemIsSelected) {
                        menu.add(new Ext.menu.Item({
                            text: t('pim.dataport.move-to-selection').replace('%s', selectedRows[selectedRows.length - 1].get('name')),
                            iconCls: "opendxp_icon_table_row",
                            handler: function (menuItem, e) {
                                store.data.remove(record);
                                store.data.insert(store.indexOf(selectedRows[selectedRows.length - 1]) + 1, record);
                            }.bind(this),
                        }));
                    }

                    if (record.get('sort') === null || record.get('sort') === 'ASC') {
                        menu.add(new Ext.menu.Item({
                            text: t('pim.dataport.sort-descending'),
                            icon: "/bundles/opendxpadmin/img/flat-color-icons/alphabetical_sorting_za.svg",
                            handler: function (menuItem, e) {
                                Ext.each(records, function (record) {
                                    record.set('sort', 'DESC');
                                });
                            }.bind(this),
                        }));
                    } else if (record.get('sort') === 'DESC') {
                        menu.add(new Ext.menu.Item({
                            text: t('pim.dataport.sort-ascending'),
                            icon: "/bundles/opendxpadmin/img/flat-color-icons/alphabetical_sorting_az.svg",
                            handler: function (menuItem, e) {
                                Ext.each(records, function (record) {
                                    record.set('sort', 'ASC');
                                });
                            }.bind(this),
                        }));
                    }

                    menu.add(new Ext.menu.Item({
                        text: t('add'),
                        iconCls: "opendxp_icon_add",
                        handler: this.onAddDataport.bind(this),
                    }));

                    menu.add(new Ext.menu.Item({
                        text: t('copy'),
                        iconCls: "opendxp_icon_copy",
                        handler: function () {
                            Ext.each(records, function (record) {
                                if (selectedRows.length === 1) {
                                    this.sourceConfigRawitemPanel.getSelectionModel().select(record);
                                } else {
                                    this.sourceConfigRawitemPanel.getSelectionModel().deselectAll();
                                }

                                var addedRecord = this.onAddDataport();
                                var name = record.get('name').replace(/(.*)(\d+)$/, function (input, namePrefix, trailingDigits) {
                                    return namePrefix + (parseInt(trailingDigits, 10) + 1);
                                });

                                addedRecord.set('name', name);

                                var column = record.get('column');
                                addedRecord.set('column', column);
                            }.bind(this));
                        }.bind(this)
                    }));

                    menu.add(new Ext.menu.Item({
                        text: t('delete'),
                        iconCls: "opendxp_icon_delete",
                        handler: function () {
                            this.onDeleteDataport(record);
                        }.bind(this)
                    }));

                    menu.showAt(e.getXY());

                    e.stopEvent();
                }.bind(this)
            },
            plugins: {
                ptype: 'cellediting',
                clicksToEdit: 2
            },
            viewConfig: {
                plugins: {
                    ptype: 'gridviewdragdrop',
                    pluginId: 'gridviewdragdrop',
                    dragText: t('pim.dataport.change-order-hint')
                }
            }
        });


        var reportStore = [];

        // add report groups
        var group;
        var reportClass, reportConfig;
        var reportBroker = null;

        if(typeof opendxp.bundle !== 'undefined' && typeof opendxp.bundle.customreports !== 'undefined' && typeof opendxp.bundle.customreports.broker !== "undefined") {
            reportBroker = opendxp.bundle.customreports.broker;
        } else if(typeof opendxp.report.broker !== "undefined")
        {
            reportBroker = opendxp.report.broker;
        }

        for (var i = 0; i < reportBroker.groups.length; i++) {
            group = reportBroker.groups[i];

            var groupIconCls = group.iconCls ? group.iconCls : '';
            groupIconCls = groupIconCls + ' ' + groupIconCls.replace(/^opendxp_nav_icon_/, 'opendxp_icon_');

            // add reports to group
            if (typeof reportBroker.reports[group.id] == "object") {
                for (var r = 0; r < reportBroker.reports[group.id].length; r++) {
                    reportClass = reportBroker.reports[group.id][r]["class"];

                    // currently report classes are JS-only so there is no certain way to know how to get the data (except for custom reports as they use a common interface (CustomReportAdapterInterface)
                    if (reportClass !== 'opendxp.bundle.customreports.custom.report' && reportClass !== 'opendxp.report.custom.report') {
                        continue;
                    }

                    try {
                        reportClass = stringToFunction(reportClass);
                        reportConfig = reportBroker.reports[group.id][r]["config"];
                        if (!reportConfig) {
                            reportConfig = {};
                        }

                        var iconCls = reportConfig["iconCls"] ? reportConfig["iconCls"] : reportClass.prototype.getIconCls();
                        iconCls = iconCls + ' ' + iconCls.replace(/^opendxp_nav_icon_/, 'opendxp_icon_');

                        if(reportConfig.name) {
                            reportStore.push({
                                name: reportConfig.name,
                                niceName: reportConfig["text"] ? t(reportConfig["text"]) : t(reportClass.prototype.getName()),
                                iconCls: iconCls,
                                group: group.name,
                                groupIconCls: groupIconCls
                            });
                        }
                    } catch (e) {
                        console.log(e);
                    }
                }
            }
        }
        // Panel für Importkonfiguration
        this.sourceConfigBasePanel = new Ext.form.FormPanel({
            padding: 5,
            layout: {
                type: 'vbox',
                align: 'stretch'
            },
            fieldDefaults: {
                labelAlign: 'left',
                labelWidth: 200
            },
            items: [
                {
                    xtype: 'panel',
                    title: t('settings') + ': ' +this.dataportPanel.getForm().findField('sourcetype').getStore().findRecord("code", this.dataportPanel.getForm().findField('sourcetype').getValue()).get("label"),
                    layout: {
                        type: 'vbox',
                        align: 'stretch'
                    },
                    bodyPadding: '10 0 0 10',
                    items: [
                        {
                            xtype: 'combo',
                            name: 'file',
                            fieldLabel: t('pim.dataport_sourcetype.pimcore_reports'),
                            value: this.getSourceconfig('file'),
                            queryMode: 'local',
                            editable: false,
                            forceSelection: true,
                            store: {
                                data: reportStore
                            },
                            valueField: 'name',
                            minChars: 1,
                            listConfig: {
                                tpl: [
                                    '<tpl for=".">',
                                    '{[xindex === 1 || parent[xindex - 2].group !== values.group ? "<div style=\'padding: 5px 0 5px 30px;background-position-x: left !important;\' class=\'"+values.groupIconCls+"\'>"+values.group+"</div>" : ""]}',
                                    '<div role="option" class="x-boundlist-item {iconCls}" style="background-position-x: 10px !important;padding-left: 45px;">{niceName}</div>',
                                    '</tpl>'
                                ]
                            },
                            displayTpl: [
                                '<tpl for=".">',
                                '{niceName} ({group})',
                                '</tpl>'
                            ]
                        }
                    ]},
                {
                    xtype: 'panel',
                    itemId: 'targetSettings',
                    title: t('settings') + ': ' +((this.dataport.itemClass === "0" || this.dataportPanel.getForm().findField('itemClass').getValue() === "0") ? t('export') : t('import')),
                    layout: {
                        type: 'vbox',
                        align: 'stretch'
                    },
                    bodyPadding: '10 0 0 10',
                    items: [
                        {
                            xtype: 'checkbox',
                            name: 'autoImport',
                            fieldLabel: t('pim.dataport.auto-import'),
                            value: this.getSourceconfig('autoImport'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': (this.dataport.itemClass === "0" || this.dataportPanel.getForm().findField('itemClass').getValue() === "0") ? t('pim.dataport.auto-import.tooltip.export') : t('pim.dataport.auto-import.tooltip')
                            }
                        },
                        {
                            xtype: 'textfield',
                            name: 'itemFolder',
                            hidden: this.dataport.itemClass === "OpenDxp\\Model\\Asset" || this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Asset" || this.dataport.itemClass === "0" || this.dataportPanel.getForm().findField('itemClass').getValue() === "0" || (this.sourceConfigBasePanel && this.sourceConfigBasePanel.getForm().findField('mode').getValue() == '2'),
                            fieldLabel: t('pim.dataport_targetfolder'),
                            cls: "input_drop_target",
                            inputAttrTpl: 'data-qtip="' + t('pim.dataport.folder.pimcore_or_filesystem') + '"',
                            value: this.dataport.itemFolder,
                            listeners: {
                                render: function (el) {
                                    var me = this;
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return e.getTarget();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType === "object" || data.elementType === "document") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType === "object" || data.elementType === "document") {
                                                target.value = data.path;
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    el.getEl().on('dblclick', function () {
                                        var elementType = 'object';
                                        if (this.dataport.itemClass === "OpenDxp\\Model\\Asset" || this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Asset") {
                                            elementType = 'asset';
                                        } else if (this.dataport.itemClass === "OpenDxp\\Model\\Document\\Page" || this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Document\\Page") {
                                            elementType = 'document';
                                        }
                                        opendxp.helpers.openElement(el.getValue(), elementType);
                                    }.bind(this));
                                }.bind(this)
                            }
                        },
                        {
                            xtype: 'textfield',
                            name: 'masterDocument',
                            hidden: this.dataport.itemClass !== "OpenDxp\\Model\\Document\\Page" || this.dataportPanel.getForm().findField('itemClass').getValue() !== "OpenDxp\\Model\\Document\\Page",
                            fieldLabel: t('content_master_document'),
                            cls: "input_drop_target",
                            value: this.dataport.masterDocument,
                            listeners: {
                                render: function (el) {
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return this.getEl();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "document") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "document") {
                                                this.setValue(data.path);
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    this.getEl().on('dblclick', function () {
                                        opendxp.helpers.openElement(this.getValue(), 'document');
                                    }.bind(this));
                                }
                            }
                        },
                        {
                            xtype: 'textfield',
                            name: 'assetSource',
                            fieldLabel: t('pim.dataport.csv.assetSource'),
                            cls: "input_drop_target",
                            listeners: {
                                render: function (el) {
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return this.getEl();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                this.setValue(data.path);
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    this.getEl().on('dblclick', function () {
                                        opendxp.helpers.openElement(this.getValue(), 'asset');
                                    }.bind(this));
                                }
                            },
                            value: this.getSourceconfig('assetSource')
                        },
                        {
                            xtype: 'textfield',
                            name: 'assetFolder',
                            fieldLabel: t('pim.dataport_assetfolder'),
                            cls: "input_drop_target",
                            inputAttrTpl: 'data-qtip="' + t('pim.dataport.folder.pimcore_or_filesystem') + '"',
                            listeners: {
                                render: function (el) {
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return this.getEl();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                this.setValue(data.path);
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    this.getEl().on('dblclick', function () {
                                        opendxp.helpers.openElement(this.getValue(), 'asset');
                                    }.bind(this));
                                }
                            },
                            value: this.dataport.assetFolder
                        }
                    ]},
                {
                    xtype: 'panel',
                    collapsible: true,
                    collapsed: true,
                    title: t('pim.dataport_advancedOptions'),
                    cls: 'x-panel-header-light',
                    style: 'border: none',
                    bodyPadding: '10 0 0 10',
                    headerOverCls: 'x-fieldset-header-text-collapsible',
                    titleCollapse: true,
                    fieldDefaults: {
                        labelAlign: 'left',
                        labelWidth: 200
                    },
                    layout: {
                        type: 'vbox',
                        align: 'stretch'
                    },
                    items: [
                        {
                            xtype: 'combo',
                            name: 'mode',
                            fieldLabel: t('pim.dataport_mode'),
                            editable: false,
                            forceSelection: true,
                            store: [
                                ['3', t('pim.dataport_mode.create_and_edit')],
                                ['1', t('pim.dataport_mode.create_only')],
                                ['2', t('pim.dataport_mode.edit_only')]
                            ],
                            value: this.dataport.mode
                        },
                        {
                            xtype: 'checkbox',
                            name: 'compatibilityMode',
                            value: this.dataport.compatibilityMode,
                            fieldLabel: t('pim.compatibilityMode'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.compatibilityMode.tooltip')
                            }
                        },
                        {
                            xtype: 'combo',
                            name: 'javascriptEngine',
                            fieldLabel: t('pim.dataport_javascriptEngine'),
                            hidden: true,
                            editable: false,
                            triggerAction: 'all',
                            store: Ext.create('Ext.data.JsonStore', {
                                model: 'JavascriptEngineModel',
                                proxy: {
                                    type: 'ajax',
                                    url: '/admin/SylphenDataBridge/importconfig/get-javascript-engines',
                                    reader: {
                                        type: 'json',
                                        rootProperty: 'data'
                                    }

                                },
                                autoLoad: true
                            }),
                            valueField: 'id',
                            displayField: 'name',
                            value: this.dataport.javascriptEngine,
                            listeners: {
                                select: function (comp, record, index) {
                                    if (comp.getValue() == "" || comp.getValue() == "&nbsp;") {
                                        comp.setValue(null);
                                    }
                                }
                            }
                        },
                        {
                            xtype: 'checkbox',
                            name: 'optimizeInheritance',
                            value: this.dataport.optimizeInheritance,
                            fieldLabel: t('pim.dataport_optimize_inheritance'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport_optimize_inheritance.tooltip')
                            }
                        },
                        {
                            xtype: 'numberfield',
                            minValue: 0,
                            decimalPrecision: 0,
                            name: 'parallelProcesses',
                            value: this.dataport.parallelProcesses,
                            fieldLabel: t('pim.dataport_parallel_processes'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport_parallel_processes.tooltip')
                            }
                        },
                        {
                            xtype: 'checkbox',
                            name: 'skipVersioning',
                            fieldLabel: t('pim.dataport_skip_versioning'),
                            value: this.dataport.skipVersioning,
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport_skip_versioning.tooltip')
                            },
                            hidden: true
                        }, {
                            xtype: 'textfield',
                            name: 'idPrefix',
                            value: this.dataport.idPrefix,
                            fieldLabel: t('pim.dataport_idprefix'),
                            allowBlank: true,
                            hidden: true
                        }, {
                            xtype: 'textfield',
                            name: 'categoryClass',
                            value: this.dataport.categoryClass,
                            fieldLabel: t('pim.dataport_categoryclass'),
                            allowBlank: true,
                            hidden: true
                        }, {
                            xtype: 'textfield',
                            name: 'fieldnameProducts',
                            value: this.dataport.fieldnameProducts,
                            fieldLabel: t('pim.dataport_fieldnameProducts'),
                            allowBlank: true,
                            hidden: true
                        },
                        {
                            xtype: 'tagfield',
                            name: 'error_recipients[]',
                            fieldLabel: t('pim.dataport.send_error_notification'),
                            store: Ext.create('Ext.data.JsonStore', {
                                proxy: {
                                    type: 'ajax',
                                    url: '/admin/SylphenDataBridge/importconfig/get-recipients/' + this.dataport.id,
                                    fields: ['name', 'userId'],
                                    reader: {
                                        type: 'json',
                                        rootProperty: 'data'
                                    }

                                },
                                autoLoad: true
                            }),
                            value: this.dataport.errorRecipients,
                            valueField: 'userId',
                            displayField: 'name',
                            filterPickList: true,
                            forceSelection: true,
                            queryMode: 'local',
                            anyMatch: true
                        }
                    ]
                }
                ]
        });

        this.sourceConfigPanel.add(this.sourceConfigBasePanel, this.sourceConfigRawitemPanel);

        opendxp.layout.refresh();
    },

    renderGridSourceConfigPanel: function () {
        var additionalColumns = this.getColumnConfigForSourcetype('grid');

        // Rohdatenfeldkonfiguration
        if (typeof GridImportconfigModel == 'undefined') {
            var readerFields = [
                { name: 'fieldNo', allowBlank: false, type: 'integer' },
                { name: 'sort', allowBlank: false },
                { name: 'field', allowBlank: false }
            ];

            Ext.each(additionalColumns, function (column) {
                readerFields.push({ name: column.dataIndex });
            });

            readerFields.push({ name: 'data1', persist: false });

            Ext.define('GridImportconfigModel', {
                    extend: 'Ext.data.Model',
                    fields: readerFields,
                    idProperty: 'fieldNo',
                    root: 'fields'
                }
            );
        }

        var store = new Ext.data.JsonStore({
            model: 'GridImportconfigModel',
            proxy: {
                type: 'ajax',
                url: '/admin/SylphenDataBridge/importconfig/get-rawitemfield-config/' + this.dataport.id,
                reader: {
                    type: 'json',
                    rootProperty: 'fields',
                    messageProperty: 'errorMessage'
                }

            },
            listeners: {
                load: function () {
                    Ext.Ajax.request({
                        url: '/admin/SylphenDataBridge/importconfig/get-demo-data',
                        params: {
                            dataportId: this.dataportId
                        },
                        success: function (response) {
                            response = Ext.decode(response.responseText);

                            var store = this.sourceConfigRawitemPanel.getStore();
                            Ext.each(response.data, function (value) {
                                var record = store.findRecord('fieldNo', value.fieldNo, 0, false, true, true)

                                if (record) {
                                    record.set('data1', value.value);
                                }
                            }.bind(this));

                            this.sourceConfigRawitemPanel.getView().refresh();
                        }.bind(this)
                    });
                }.bind(this)
            },
            autoLoad: true
        });

        var columnConfig = [];
        columnConfig.push({
            header: "#",
            width: 50,
            dataIndex: 'fieldNo',
            renderer: function (value, metaData, record) {
                if (record.get('sort') === 'DESC') {
                    metaData.tdAttr = 'data-qtip="' + t('pim.dataport-fields.sorting-direction.desc') + '"';
                    value += ' <img src="/bundles/opendxpadmin/img/flat-color-icons/alphabetical_sorting_za.svg" height="18" width="18" style="height:18px;vertical-align:middle">';
                } else {
                    metaData.tdAttr = 'data-qtip="' + t('pim.dataport-fields.sorting-direction.asc') + '"';
                }
                return value;
            }
        });
        columnConfig.push({
            header: t('pim.dataport-fields-name'),
            dataIndex: 'name',
            flex: 1,
            width: 'auto',
            renderer: function (value, metaData, record) {
                if (!value) {
                    return '';
                }

                var mappingRecords = this.mappingPanel.getStore().queryBy(function () { return true; }).getRange();
                var isMapped = false;
                var minOneFieldIsMapped = false;
                Ext.each(mappingRecords, function (mappingRecord) {
                    if (mappingRecord.get('field')) {
                        minOneFieldIsMapped = true;
                    }

                    if (record.get('fieldNo') === mappingRecord.get('field')) {
                        isMapped = true;
                        return false;
                    }

                    var mappingRecordSettings = mappingRecord.get('settings');
                    if (mappingRecordSettings.calculation) {
                        minOneFieldIsMapped = true;
                        if (this.dataport.javascriptEngine === 'php' && mappingRecordSettings.calculation.indexOf('$params[\'rawItemData\'][\'' + value + '\']') > -1) {
                            isMapped = true;
                            return false;
                        } else if (this.dataport.javascriptEngine === 'v8js' && (mappingRecordSettings.calculation.indexOf('params[\'rawItemData\'][\'' + value + '\']') > -1 || mappingRecordSettings.calculation.indexOf('params.rawItemData.' + value) > -1)) {
                            isMapped = true;
                            return false;
                        }
                    }
                }.bind(this));

                if (!isMapped && minOneFieldIsMapped) {
                    metaData.tdAttr = 'data-qtip="' + t('pim.mapping.variables.variable.not_in_use') + '"';
                    return '<s>' + Ext.util.Format.htmlEncode(value) + '</s>';
                }
                return Ext.util.Format.htmlEncode(value);
            }.bind(this)
        });

        Ext.each(additionalColumns, function (column) {
            columnConfig.push(column);
        });

        columnConfig.push({
            header: t('pim.dataport-fields-ex1'),
            dataIndex: 'data1',
            flex: 1,
            renderer: function (value, metaData, record) {
                if (typeof value === 'undefined') {
                    return '';
                }
                return Ext.util.Format.htmlEncode(value);
            }
        });

        this.sourceConfigRawitemPanel = Ext.create('Ext.grid.Panel', {
            title: '',
            store: store,
            columns: {
                defaults: {
                    menuDisabled: true,
                    sortable: false
                },
                items: columnConfig
            },
            flex: 1,
            border: false,
            frame: false,
            autoScroll: true,
            layout: 'fit',
            minHeight: 300,
            selModel: {
                selType: 'rowmodel',
                mode: 'MULTI',
                ignoreRightMouseSelection: true
            },
            multiSelect: true,
            dockedItems: [{
                xtype: 'toolbar',
                dock: 'top',
                items: [
                    {
                        xtype: 'label',
                        html: t('pim.rawdatafields'),
                        cls: 'x-panel-header-title-default x-panel-header-default',
                        style: 'border:none;'
                    }]
            }]
        });

        // Panel für Importkonfiguration
        this.sourceConfigBasePanel = new Ext.form.FormPanel({
            padding: 5,
            layout: {
                type: 'vbox',
                align: 'stretch'
            },
            fieldDefaults: {
                labelAlign: 'left',
                labelWidth: 200
            },
            items: [
                {
                    xtype: 'panel',
                    title: t('settings') + ': ' + this.dataportPanel.getForm().findField('sourcetype').getStore().findRecord("code", this.dataportPanel.getForm().findField('sourcetype').getValue()).get("label"),
                    layout: {
                        type: 'vbox',
                        align: 'stretch'
                    },
                    bodyPadding: '10 0 0 10',
                    items: [
                        {
                            xtype: 'combo',
                            name: 'file',
                            fieldLabel: t('grid_configuration'),
                            displayField: 'name',
                            valueField: 'id',
                            value: this.getSourceconfig('file'),
                            queryMode: 'local',
                            anyMatch: true,
                            editable: true,
                            forceSelection: true,
                            store: Ext.create('Ext.data.JsonStore', {
                                proxy: {
                                    type: 'ajax',
                                    url: '/admin/SylphenDataBridge/importconfig/get-grid-configs',
                                    reader: {
                                        type: 'json',
                                        rootProperty: 'data'
                                    }

                                },
                                fields: ['id','name','group','icon'],
                                autoLoad: true
                            }),
                            minChars: 1,
                            listConfig: {
                                tpl: [
                                    '<tpl for=".">',
                                    '{[xindex === 1 || parent[xindex - 2].group !== values.group ? "<div style=\'padding: 5px 0 5px 30px;background: url("+values.icon+") left center no-repeat\'>"+values.group+"</div>" : ""]}',
                                    '<div role="option" class="x-boundlist-item" style="background-position-x: 10px !important;padding-left: 45px;">{name}</div>',
                                    '</tpl>'
                                ]
                            },
                            displayTpl: [
                                '<tpl for=".">',
                                '{name} ({group})',
                                '</tpl>'
                            ],
                            listeners: {
                                focus: function (combo) {
                                    setTimeout(function () {
                                        if (!combo.isExpanded) {
                                            combo.expand();
                                        }
                                    }, 100);
                                }
                            }
                        }
                    ]
                },
                {
                    xtype: 'panel',
                    itemId: 'targetSettings',
                    title: t('settings') + ': ' + ((this.dataport.itemClass === "0" || this.dataportPanel.getForm().findField('itemClass').getValue() === "0") ? t('export') : t('import')),
                    layout: {
                        type: 'vbox',
                        align: 'stretch'
                    },
                    bodyPadding: '10 0 0 10',
                    items: [
                        {
                            xtype: 'textfield',
                            name: 'itemFolder',
                            hidden: this.dataport.itemClass === "OpenDxp\\Model\\Asset" || this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Asset" || this.dataport.itemClass === "0" || this.dataportPanel.getForm().findField('itemClass').getValue() === "0" || (this.sourceConfigBasePanel && this.sourceConfigBasePanel.getForm().findField('mode').getValue() == '2'),
                            fieldLabel: t('pim.dataport_targetfolder'),
                            cls: "input_drop_target",
                            inputAttrTpl: 'data-qtip="' + t('pim.dataport.folder.pimcore_or_filesystem') + '"',
                            value: this.dataport.itemFolder,
                            listeners: {
                                render: function (el) {
                                    var me = this;
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return e.getTarget();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType === "object" || data.elementType === "document") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType === "object" || data.elementType === "document") {
                                                target.value = data.path;
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    el.getEl().on('dblclick', function () {
                                        var elementType = 'object';
                                        if (this.dataport.itemClass === "OpenDxp\\Model\\Asset" || this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Asset") {
                                            elementType = 'asset';
                                        } else if (this.dataport.itemClass === "OpenDxp\\Model\\Document\\Page" || this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Document\\Page") {
                                            elementType = 'document';
                                        }
                                        opendxp.helpers.openElement(el.getValue(), elementType);
                                    }.bind(this));
                                }.bind(this)
                            }
                        },
                        {
                            xtype: 'textfield',
                            name: 'masterDocument',
                            hidden: this.dataport.itemClass !== "OpenDxp\\Model\\Document\\Page" || this.dataportPanel.getForm().findField('itemClass').getValue() !== "OpenDxp\\Model\\Document\\Page",
                            fieldLabel: t('content_master_document'),
                            cls: "input_drop_target",
                            value: this.dataport.masterDocument,
                            listeners: {
                                render: function (el) {
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return this.getEl();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "document") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "document") {
                                                this.setValue(data.path);
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    this.getEl().on('dblclick', function () {
                                        opendxp.helpers.openElement(this.getValue(), 'document');
                                    }.bind(this));
                                }
                            }
                        },
                        {
                            xtype: 'textfield',
                            name: 'assetSource',
                            fieldLabel: t('pim.dataport.csv.assetSource'),
                            cls: "input_drop_target",
                            listeners: {
                                render: function (el) {
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return this.getEl();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                this.setValue(data.path);
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    this.getEl().on('dblclick', function () {
                                        opendxp.helpers.openElement(this.getValue(), 'asset');
                                    }.bind(this));
                                }
                            },
                            value: this.getSourceconfig('assetSource')
                        },
                        {
                            xtype: 'textfield',
                            name: 'assetFolder',
                            fieldLabel: t('pim.dataport_assetfolder'),
                            cls: "input_drop_target",
                            inputAttrTpl: 'data-qtip="' + t('pim.dataport.folder.pimcore_or_filesystem') + '"',
                            listeners: {
                                render: function (el) {
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return this.getEl();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                this.setValue(data.path);
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    this.getEl().on('dblclick', function () {
                                        opendxp.helpers.openElement(this.getValue(), 'asset');
                                    }.bind(this));
                                }
                            },
                            value: this.dataport.assetFolder
                        }
                    ]
                },
                {
                    xtype: 'panel',
                    collapsible: true,
                    collapsed: true,
                    title: t('pim.dataport_advancedOptions'),
                    cls: 'x-panel-header-light',
                    style: 'border: none',
                    bodyPadding: '10 0 0 10',
                    headerOverCls: 'x-fieldset-header-text-collapsible',
                    titleCollapse: true,
                    fieldDefaults: {
                        labelAlign: 'left',
                        labelWidth: 200
                    },
                    layout: {
                        type: 'vbox',
                        align: 'stretch'
                    },
                    items: [
                        {
                            xtype: 'combo',
                            name: 'mode',
                            fieldLabel: t('pim.dataport_mode'),
                            editable: false,
                            forceSelection: true,
                            store: [
                                ['3', t('pim.dataport_mode.create_and_edit')],
                                ['1', t('pim.dataport_mode.create_only')],
                                ['2', t('pim.dataport_mode.edit_only')]
                            ],
                            value: this.dataport.mode
                        },
                        {
                            xtype: 'checkbox',
                            name: 'compatibilityMode',
                            value: this.dataport.compatibilityMode,
                            fieldLabel: t('pim.compatibilityMode'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.compatibilityMode.tooltip')
                            }
                        },
                        {
                            xtype: 'combo',
                            name: 'javascriptEngine',
                            fieldLabel: t('pim.dataport_javascriptEngine'),
                            hidden: true,
                            editable: false,
                            triggerAction: 'all',
                            store: Ext.create('Ext.data.JsonStore', {
                                model: 'JavascriptEngineModel',
                                proxy: {
                                    type: 'ajax',
                                    url: '/admin/SylphenDataBridge/importconfig/get-javascript-engines',
                                    reader: {
                                        type: 'json',
                                        rootProperty: 'data'
                                    }

                                },
                                autoLoad: true
                            }),
                            valueField: 'id',
                            displayField: 'name',
                            value: this.dataport.javascriptEngine,
                            listeners: {
                                select: function (comp, record, index) {
                                    if (comp.getValue() == "" || comp.getValue() == "&nbsp;") {
                                        comp.setValue(null);
                                    }
                                }
                            }
                        },
                        {
                            xtype: 'checkbox',
                            name: 'optimizeInheritance',
                            value: this.dataport.optimizeInheritance,
                            fieldLabel: t('pim.dataport_optimize_inheritance'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport_optimize_inheritance.tooltip')
                            }
                        },
                        {
                            xtype: 'numberfield',
                            minValue: 0,
                            decimalPrecision: 0,
                            name: 'parallelProcesses',
                            value: this.dataport.parallelProcesses,
                            fieldLabel: t('pim.dataport_parallel_processes'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport_parallel_processes.tooltip')
                            }
                        },
                        {
                            xtype: 'checkbox',
                            name: 'skipVersioning',
                            fieldLabel: t('pim.dataport_skip_versioning'),
                            value: this.dataport.skipVersioning,
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport_skip_versioning.tooltip')
                            },
                            hidden: true
                        }, {
                            xtype: 'textfield',
                            name: 'idPrefix',
                            value: this.dataport.idPrefix,
                            fieldLabel: t('pim.dataport_idprefix'),
                            allowBlank: true,
                            hidden: true
                        }, {
                            xtype: 'textfield',
                            name: 'categoryClass',
                            value: this.dataport.categoryClass,
                            fieldLabel: t('pim.dataport_categoryclass'),
                            allowBlank: true,
                            hidden: true
                        }, {
                            xtype: 'textfield',
                            name: 'fieldnameProducts',
                            value: this.dataport.fieldnameProducts,
                            fieldLabel: t('pim.dataport_fieldnameProducts'),
                            allowBlank: true,
                            hidden: true
                        },
                        {
                            xtype: 'tagfield',
                            name: 'error_recipients[]',
                            fieldLabel: t('pim.dataport.send_error_notification'),
                            store: Ext.create('Ext.data.JsonStore', {
                                proxy: {
                                    type: 'ajax',
                                    url: '/admin/SylphenDataBridge/importconfig/get-recipients/' + this.dataport.id,
                                    fields: ['name', 'userId'],
                                    reader: {
                                        type: 'json',
                                        rootProperty: 'data'
                                    }

                                },
                                autoLoad: true
                            }),
                            value: this.dataport.errorRecipients,
                            valueField: 'userId',
                            displayField: 'name',
                            filterPickList: true,
                            forceSelection: true,
                            queryMode: 'local',
                            anyMatch: true
                        }
                    ]
                }
            ]
        });

        this.sourceConfigPanel.add(this.sourceConfigBasePanel, this.sourceConfigRawitemPanel);

        opendxp.layout.refresh();
    },

    renderFilesSourceConfigPanel: function () {
        var additionalColumns = this.getColumnConfigForSourcetype('files');

        // Rohdatenfeldkonfiguration
        if (typeof FilesImportconfigModel == 'undefined') {
            var readerFields = [
                {name: 'fieldNo', allowBlank: false, type: 'integer'},
                { name: 'sort', allowBlank: false },
                {name: 'name', allowBlank: false}
            ];

            Ext.each(additionalColumns, function (column) {
                readerFields.push({name: column.dataIndex});
            });

            readerFields.push({name: 'data1', persist: false});

            Ext.define('FilesImportconfigModel', {
                extend: 'Ext.data.Model',
                fields: readerFields,
                idProperty: 'fieldNo',
                root: 'fields'
            });
        }

        var store = new Ext.data.JsonStore({
            model: 'FilesImportconfigModel',
            proxy: {
                type: 'ajax',
                url: '/admin/SylphenDataBridge/importconfig/get-rawitemfield-config/' + this.dataport.id,
                reader: {
                    type: 'json',
                    rootProperty: 'fields',
                    messageProperty: 'errorMessage'
                }

            },
            listeners: {
                load: function () {
                    Ext.Ajax.request({
                        url: '/admin/SylphenDataBridge/importconfig/get-demo-data',
                        params: {
                            dataportId: this.dataportId
                        },
                        success: function (response) {
                            response = Ext.decode(response.responseText);

                            var store = this.sourceConfigRawitemPanel.getStore();
                            Ext.each(response.data, function (value) {
                                var record = store.findRecord('fieldNo', value.fieldNo, 0, false, true, true)

                                if (record) {
                                    record.set('data1', value.value);
                                }
                            }.bind(this));

                            this.sourceConfigRawitemPanel.getView().refresh();
                        }.bind(this)
                    });
                }.bind(this)
            },
            autoLoad: true
        });

        // Panel für Importkonfiguration
        this.sourceConfigBasePanel = new Ext.form.FormPanel({
            padding: 5,
            layout: {
                type: 'vbox',
                align: 'stretch'
            },
            fieldDefaults: {
                labelAlign: 'left',
                labelWidth: 200
            },
            items: [
                {
                    xtype: 'panel',
                    title: t('settings') + ': ' + this.dataportPanel.getForm().findField('sourcetype').getStore().findRecord("code", this.dataportPanel.getForm().findField('sourcetype').getValue()).get("label"),
                    layout: {
                        type: 'vbox',
                        align: 'stretch'
                    },
                    bodyPadding: '10 0 0 10',
                    items: [
                        {
                            xtype: 'textarea',
                            name: 'file',
                            fieldLabel: t('pim.dataport.file.files'),
                            value: this.getSourceconfig('file'),
                            grow: true,
                            growMin: 30,
                            growAppend: '',
                            fieldStyle: 'background: url(/bundles/opendxpadmin/img/flat-color-icons/target.svg) right 5px center/20px no-repeat transparent !important;',
                            listeners: {
                                render: function (el) {
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return this.getEl();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset") {
                                                this.setValue(data.path);
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    this.getEl().on('dblclick', function () {
                                        if (this.getValue().length < 512) {
                                            Ext.Ajax.request({
                                                url: '/admin/element/get-subtype',
                                                params: {
                                                    id: this.getValue(),
                                                    type: 'asset'
                                                },
                                                success: function (response) {
                                                    var res = Ext.decode(response.responseText);
                                                    if (res.success) {
                                                        opendxp.helpers.openElement(res.id, res.type, res.subtype);
                                                    }
                                                }
                                            });
                                        }
                                    }.bind(this));
                                }
                            }
                        }
                    ]}
            , {
                    xtype: 'panel',
                    itemId: 'targetSettings',
                    title: t('settings')+': '+((this.dataport.itemClass === "0" || this.dataportPanel.getForm().findField('itemClass').getValue() === "0") ? t('export') : t('import')),
                    layout: {
                        type: 'vbox',
                        align: 'stretch'
                    },
                    bodyPadding: '10 0 0 10',
                    items: [
                        {
                            xtype: 'checkbox',
                            name: 'autoImport',
                            fieldLabel: t('pim.dataport.auto-import'),
                            value: this.getSourceconfig('autoImport'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': (this.dataport.itemClass === "0" || this.dataportPanel.getForm().findField('itemClass').getValue() === "0") ? t('pim.dataport.auto-import.tooltip.export') : t('pim.dataport.auto-import.tooltip')
                            },
                            listeners: {
                                change: function (el, newValue) {
                                    if (!newValue) {
                                        this.sourceConfigBasePanel.getForm().findField('incrementalExport').hide();
                                    } else if (this.dataportPanel.getForm().findField('itemClass').getValue() === '0') {
                                        this.sourceConfigBasePanel.getForm().findField('incrementalExport').show();
                                    }
                                }.bind(this)
                            }
                        },
                        {
                            xtype: 'checkbox',
                            name: 'incrementalExport',
                            fieldLabel: t('pim.dataport.incremental_export'),
                            value: this.getSourceconfig('incrementalExport'),
                            hidden: this.dataport.itemClass !== "0" || this.dataportPanel.getForm().findField('itemClass').getValue() !== "0",
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport.incremental_export.tooltip')
                            }
                        }, {
                            xtype: 'textfield',
                            name: 'itemFolder',
                            hidden: this.dataport.itemClass === "OpenDxp\\Model\\Asset" || this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Asset" || this.dataport.itemClass === "0" || this.dataportPanel.getForm().findField('itemClass').getValue() === "0" || (this.sourceConfigBasePanel && this.sourceConfigBasePanel.getForm().findField('mode').getValue() == '2'),
                            fieldLabel: t('pim.dataport_targetfolder'),
                            cls: "input_drop_target",
                            inputAttrTpl: 'data-qtip="' + t('pim.dataport.folder.pimcore_or_filesystem') + '"',
                            value: this.dataport.itemFolder,
                            listeners: {
                                render: function (el) {
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return e.getTarget();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "object" || data.elementType == "document") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "object" || data.elementType == "document") {
                                                target.value = data.path;
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    el.getEl().on('dblclick', function () {
                                        var elementType = 'object';
                                        if (this.dataport.itemClass === "OpenDxp\\Model\\Asset" || this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Asset") {
                                            elementType = 'asset';
                                        } else if (this.dataport.itemClass === "OpenDxp\\Model\\Document\\Page" || this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Document\\Page") {
                                            elementType = 'document';
                                        }
                                        opendxp.helpers.openElement(el.getValue(), elementType);
                                    }.bind(this));
                                }.bind(this)
                            }
                        }, {
                            xtype: 'textfield',
                            name: 'masterDocument',
                            hidden: this.dataport.itemClass !== "OpenDxp\\Model\\Document\\Page" || this.dataportPanel.getForm().findField('itemClass').getValue() !== "OpenDxp\\Model\\Document\\Page",
                            fieldLabel: t('content_master_document'),
                            cls: "input_drop_target",
                            value: this.dataport.masterDocument,
                            listeners: {
                                render: function (el) {
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return this.getEl();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "document") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "document") {
                                                this.setValue(data.path);
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    this.getEl().on('dblclick', function () {
                                        opendxp.helpers.openElement(this.getValue(), 'document');
                                    }.bind(this));
                                }
                            }
                        }, {
                            xtype: 'textfield',
                            name: 'assetSource',
                            hidden: this.dataport.itemClass === "OpenDxp\\Model\\Asset" || this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Asset",
                            fieldLabel: t('pim.dataport.excel.assetSource'),
                            cls: "input_drop_target",
                            inputAttrTpl: 'data-qtip="' + t('pim.dataport.folder.pimcore_or_filesystem') + '"',
                            listeners: {
                                render: function (el) {
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return this.getEl();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                this.setValue(data.path);
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    this.getEl().on('dblclick', function () {
                                        opendxp.helpers.openElement(this.getValue(), 'asset');
                                    }.bind(this));
                                }
                            },
                            value: this.getSourceconfig('assetSource')
                        },
                        {
                            xtype: 'textfield',
                            name: 'assetFolder',
                            fieldLabel: t('pim.dataport_assetfolder'),
                            cls: "input_drop_target",
                            inputAttrTpl: 'data-qtip="' + t('pim.dataport.folder.pimcore_or_filesystem') + '"',
                            listeners: {
                                render: function (el) {
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return this.getEl();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                this.setValue(data.path);
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    this.getEl().on('dblclick', function () {
                                        opendxp.helpers.openElement(this.getValue(), 'asset');
                                    }.bind(this));
                                }
                            },
                            value: this.dataport.assetFolder
                        },
                        {
                            xtype: 'textfield',
                            name: 'archiveFolder',
                            fieldLabel: t('pim.dataport_archiveFolder'),
                            cls: "input_drop_target",
                            value: this.getSourceconfig('archiveFolder'),
                            listeners: {
                                render: function (el) {
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return this.getEl();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                this.setValue(data.path);
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    this.getEl().on('dblclick', function () {
                                        opendxp.helpers.openElement(this.getValue(), 'asset');
                                    }.bind(this));
                                }
                            }
                        }
                    ]},
                {
                    xtype: 'panel',
                    collapsible: true,
                    collapsed: true,
                    title: t('pim.dataport_advancedOptions'),
                    cls: 'x-panel-header-light',
                    style: 'border: none',
                    bodyPadding: '10 0 0 10',
                    headerOverCls: 'x-fieldset-header-text-collapsible',
                    titleCollapse: true,
                    fieldDefaults: {
                        labelAlign: 'left',
                        labelWidth: 200
                    },
                    layout: {
                        type: 'vbox',
                        align: 'stretch'
                    },
                    items: [
                        {
                            xtype: 'combo',
                            name: 'mode',
                            fieldLabel: t('pim.dataport_mode'),
                            editable: false,
                            forceSelection: true,
                            store: [
                                ['3', t('pim.dataport_mode.create_and_edit')],
                                ['1', t('pim.dataport_mode.create_only')],
                                ['2', t('pim.dataport_mode.edit_only')]
                            ],
                            value: this.dataport.mode
                        },
                        {
                            xtype: 'checkbox',
                            name: 'compatibilityMode',
                            value: this.dataport.compatibilityMode,
                            fieldLabel: t('pim.compatibilityMode'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.compatibilityMode.tooltip')
                            }
                        },
                        {
                            xtype: 'combo',
                            name: 'javascriptEngine',
                            fieldLabel: t('pim.dataport_javascriptEngine'),
                            hidden: true,
                            editable: false,
                            triggerAction: 'all',
                            store: Ext.create('Ext.data.JsonStore', {
                                model: 'JavascriptEngineModel',
                                proxy: {
                                    type: 'ajax',
                                    url: '/admin/SylphenDataBridge/importconfig/get-javascript-engines',
                                    reader: {
                                        type: 'json',
                                        rootProperty: 'data'
                                    }

                                },
                                autoLoad: true
                            }),
                            valueField: 'id',
                            displayField: 'name',
                            value: this.dataport.javascriptEngine,
                            listeners: {
                                select: function (comp, record, index) {
                                    if (comp.getValue() == "" || comp.getValue() == "&nbsp;") {
                                        comp.setValue(null);
                                    }
                                }
                            }
                        },
                        {
                            xtype: 'checkbox',
                            name: 'optimizeInheritance',
                            value: this.dataport.optimizeInheritance,
                            fieldLabel: t('pim.dataport_optimize_inheritance'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport_optimize_inheritance.tooltip')
                            }
                        },
                        {
                            xtype: 'numberfield',
                            minValue: 0,
                            decimalPrecision: 0,
                            name: 'parallelProcesses',
                            value: this.dataport.parallelProcesses,
                            fieldLabel: t('pim.dataport_parallel_processes'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport_parallel_processes.tooltip')
                            }
                        },
                        {
                            xtype: 'checkbox',
                            name: 'skipVersioning',
                            fieldLabel: t('pim.dataport_skip_versioning'),
                            value: this.dataport.skipVersioning,
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport_skip_versioning.tooltip')
                            },
                            hidden: true
                        }, {
                            xtype: 'textfield',
                            name: 'idPrefix',
                            value: this.dataport.idPrefix,
                            fieldLabel: t('pim.dataport_idprefix'),
                            allowBlank: true,
                            hidden: true
                        }, {
                            xtype: 'textfield',
                            name: 'categoryClass',
                            value: this.dataport.categoryClass,
                            fieldLabel: t('pim.dataport_categoryclass'),
                            allowBlank: true,
                            hidden: true
                        }, {
                            xtype: 'textfield',
                            name: 'fieldnameProducts',
                            value: this.dataport.fieldnameProducts,
                            fieldLabel: t('pim.dataport_fieldnameProducts'),
                            allowBlank: true,
                            hidden: true
                        },
                        {
                            xtype: 'tagfield',
                            name: 'error_recipients[]',
                            fieldLabel: t('pim.dataport.send_error_notification'),
                            store: Ext.create('Ext.data.JsonStore', {
                                proxy: {
                                    type: 'ajax',
                                    url: '/admin/SylphenDataBridge/importconfig/get-recipients/' + this.dataport.id,
                                    fields: ['name', 'userId'],
                                    reader: {
                                        type: 'json',
                                        rootProperty: 'data'
                                    }

                                },
                                autoLoad: true
                            }),
                            value: this.dataport.errorRecipients,
                            valueField: 'userId',
                            displayField: 'name',
                            filterPickList: true,
                            forceSelection: true,
                            queryMode: 'local',
                            anyMatch: true
                        }
                    ]
                }
            ]
        });

        var columnConfig = [];
        columnConfig.push({
            header: "#",
            width: 50,
            dataIndex: 'fieldNo',
            renderer: function (value, metaData, record) {
                if (record.get('sort') === 'DESC') {
                    metaData.tdAttr = 'data-qtip="' + t('pim.dataport-fields.sorting-direction.desc') + '"';
                    value += ' <img src="/bundles/opendxpadmin/img/flat-color-icons/alphabetical_sorting_za.svg" height="18" width="18" style="height:18px;vertical-align:middle">';
                } else {
                    metaData.tdAttr = 'data-qtip="' + t('pim.dataport-fields.sorting-direction.asc') + '"';
                }
                return value;
            }
        });
        columnConfig.push({
            header: t('pim.dataport-fields-name'),
            width: 'auto',
            dataIndex: 'name',
            flex: 1,
            renderer: function (value, metaData, record) {
                if (!value) {
                    return '';
                }

                var mappingRecords = this.mappingPanel.getStore().queryBy(function () { return true; }).getRange();
                var isMapped = false;
                var minOneFieldIsMapped = false;
                Ext.each(mappingRecords, function (mappingRecord) {
                    if (mappingRecord.get('field')) {
                        minOneFieldIsMapped = true;
                    }

                    if (record.get('fieldNo') === mappingRecord.get('field')) {
                        isMapped = true;
                        return false;
                    }

                    var mappingRecordSettings = mappingRecord.get('settings');
                    if (mappingRecordSettings.calculation) {
                        minOneFieldIsMapped = true;
                        if (this.dataport.javascriptEngine === 'php' && mappingRecordSettings.calculation.indexOf('$params[\'rawItemData\'][\'' + value + '\']') > -1) {
                            isMapped = true;
                            return false;
                        } else if (this.dataport.javascriptEngine === 'v8js' && (mappingRecordSettings.calculation.indexOf('params[\'rawItemData\'][\'' + value + '\']') > -1 || mappingRecordSettings.calculation.indexOf('params.rawItemData.' + value) > -1)) {
                            isMapped = true;
                            return false;
                        }
                    }
                }.bind(this));

                if (!isMapped && minOneFieldIsMapped) {
                    metaData.tdAttr = 'data-qtip="' + t('pim.mapping.variables.variable.not_in_use') + '"';
                    return '<s>' + Ext.util.Format.htmlEncode(value) + '</s>';
                }
                return Ext.util.Format.htmlEncode(value);
            }.bind(this),
            editor: {
                xtype: 'textfield',
                allowBlank: false,
                handleMouseEvents: true,
                listeners: {
                    render: function (cmp) {
                        Ext.get(cmp.getInputId()).on('mousedown', function (e) {
                            e.stopPropagation();
                        });
                    }
                }
            }
        });

        Ext.each(additionalColumns, function (column) {
            columnConfig.push(column);
        });

        columnConfig.push({
            header: t('pim.dataport-fields-ex1'),
            dataIndex: 'data1',
            flex: 1,
            renderer: function (value, metaData, record) {
                if (typeof value === 'undefined') {
                    return '';
                }
                return Ext.util.Format.htmlEncode(value);
            }
        });

        this.sourceConfigRawitemPanel = Ext.create('Ext.grid.Panel', {
            title: '',
            store: store,
            columns: {
                defaults: {
                    menuDisabled : true,
                    sortable: false
                },
                items: columnConfig
            },
            flex: 1,
            border: false,
            frame: false,
            autoScroll: true,
            layout: 'fit',
            minHeight:300,
            selModel: {
                selType: 'rowmodel',
                mode: 'MULTI',
                ignoreRightMouseSelection: true
            },
            multiSelect: true,
            dockedItems: [{
                xtype: 'toolbar',
                dock: 'top',
                items: [
                {
                    xtype: 'label',
                    html: t('pim.rawdatafields'),
                    cls: 'x-panel-header-title-default x-panel-header-default',
                    style: 'border:none;'
                },{
                    text: t('add'),
                    handler: this.onAddDataport.bind(this),
                    iconCls: "opendxp_icon_add"
                },
                '-',
                {
                    text: t('delete'),
                    handler: function () {
                        this.onDeleteDataport();
                    }.bind(this),
                    iconCls: "opendxp_icon_delete"
                },
                '-',
                {
                    text: t('pim.rawdatafields.bulk_edit'),
                    iconCls: "opendxp_icon_edit",
                    handler: function () {
                        var displayField = {
                            xtype: "displayfield",
                            region: "north",
                            hideLabel: true,
                            border: false,
                            value: t('pim.rawdatafields.bulk_edit.description') + ':<br>' + Ext.Array.filter(Ext.Array.map(columnConfig, function (column) {
                                return column.dataIndex;
                            }), function (dataIndex) {
                                return dataIndex !== 'data1';
                            }).join(',')
                        };

                        var data = [];
                        store.each(function (rec) {
                            var row = [];
                            Ext.Array.each(columnConfig, function (column) {
                                if (column.dataIndex === 'data1') {
                                    return true;
                                }
                                row.push(rec.get(column.dataIndex));
                            });
                            data.push(row);
                        });

                        data = Ext.util.CSV.encode(data);

                        var textarea = new Ext.form.TextArea({
                            region: "center",
                            border: false,
                            value: data
                        });

                        var bulkEditWindow = new Ext.Window({
                            width: 800,
                            height: 500,
                            title: t('pim.rawdatafields.bulk_edit'),
                            iconCls: "opendxp_icon_edit",
                            layout: "fit",
                            modal: true,
                            resizable: true,
                            items: [new Ext.Panel({
                                layout: "border",
                                padding: '0 10',
                                items: [displayField, textarea]
                            })],
                            buttons: [
                                {
                                    text: t('apply'),
                                    iconCls: "opendxp_icon_save",
                                    handler: function () {
                                        store.removeAll();
                                        var content = textarea.getValue();
                                        if (content.length > 0) {
                                            var csvData = Ext.util.CSV.decode(content);

                                            for (var i = 0; i < csvData.length; i++) {
                                                var row = csvData[i];

                                                this.onAddDataport();
                                                var record = this.sourceConfigRawitemPanel.getSelectionModel().getSelection()[0];

                                                Ext.Array.each(columnConfig, function (column, index) {
                                                    record.set(column.dataIndex, row[index])
                                                });
                                            }
                                        }

                                        bulkEditWindow.close();
                                    }.bind(this)
                                },
                                {
                                    text: t('cancel'),
                                    iconCls: "opendxp_icon_empty",
                                    handler: function () {
                                        bulkEditWindow.close();
                                    }
                                }
                            ]
                        }).show();
                    }.bind(this)
                }]
            }],

            listeners: {
                beforeedit: function (editor, context) {
                    context.grid.getView().getPlugin('gridviewdragdrop').disable();
                },
                canceledit: function (editor, context) {
                    context.grid.getView().getPlugin('gridviewdragdrop').enable();
                },
                edit: function (editor, e) {
                    if (!e.record.get('name') && e.field === 'cmd' && e.value !== '') {
                        var isValid = true;

                        store.each(function (record) {
                            if (record.get('name') === e.value && record.get('fieldNo') !== e.record.get('fieldNo')) {
                                isValid = false;
                                return false;
                            }
                        });

                        if (isValid) {
                            e.record.set('name', e.value);
                        }
                    }

                    this.onAddDataport(false);

                    e.grid.getView().getPlugin('gridviewdragdrop').enable();
                }.bind(this),
                validateedit: function(editor, e) {
                    var isValid = true;

                    if(e.field === 'name') {
                        store.each(function (record) {
                            if (record.get(e.field) === e.value && e.record.get('fieldNo') !== record.get('fieldNo')) {
                                isValid = false;
                                return false;
                            }
                        });
                    }

                    if (!isValid) {
                        opendxp.helpers.showNotification(t("error"), t('pim.dataport-fields.duplicate'), "error");
                    }

                    return isValid;
                }.bind(this),
                itemcontextmenu: function(view, record, item, index, e) {
                    var menu = new Ext.menu.Menu();

                    var selectedRows = this.sourceConfigRawitemPanel.getSelectionModel().getSelection();
                    var clickedItemIsSelected = false;
                    if (selectedRows.length > 0) {
                        Ext.each(selectedRows, function (selectedRecord, index) {
                            if (record.get('fieldNo') === selectedRecord.get('fieldNo')) {
                                clickedItemIsSelected = true;
                                return false;
                            }
                        });
                    } else {
                        clickedItemIsSelected = true;
                    }

                    var records = [];
                    if (clickedItemIsSelected) {
                        records = selectedRows.reverse();
                    }

                    if (records.length === 0) {
                        records = selectedRows.length > 0 ? selectedRows : [record];
                    }

                    menu.add(new Ext.menu.Item({
                        text: t('pim.dataport.move-to-top'),
                        icon: "/bundles/opendxpadmin/img/flat-color-icons/up.svg",
                        handler: function (menuItem, e) {
                            Ext.each(records, function (record) {
                                store.data.remove(record);
                                store.data.insert(0, record);
                            });
                        }.bind(this),
                    }));

                    if (!clickedItemIsSelected) {
                        menu.add(new Ext.menu.Item({
                            text: t('pim.dataport.move-to-selection').replace('%s', selectedRows[selectedRows.length - 1].get('name')),
                            iconCls: "opendxp_icon_table_row",
                            handler: function (menuItem, e) {
                                store.data.remove(record);
                                store.data.insert(store.indexOf(selectedRows[selectedRows.length - 1]) + 1, record);
                            }.bind(this),
                        }));
                    }

                    if (record.get('sort') === null || record.get('sort') === 'ASC') {
                        menu.add(new Ext.menu.Item({
                            text: t('pim.dataport.sort-descending'),
                            icon: "/bundles/opendxpadmin/img/flat-color-icons/alphabetical_sorting_za.svg",
                            handler: function (menuItem, e) {
                                Ext.each(records, function (record) {
                                    record.set('sort', 'DESC');
                                });
                            }.bind(this),
                        }));
                    } else if (record.get('sort') === 'DESC') {
                        menu.add(new Ext.menu.Item({
                            text: t('pim.dataport.sort-ascending'),
                            icon: "/bundles/opendxpadmin/img/flat-color-icons/alphabetical_sorting_az.svg",
                            handler: function (menuItem, e) {
                                Ext.each(records, function (record) {
                                    record.set('sort', 'ASC');
                                });
                            }.bind(this),
                        }));
                    }

                    menu.add(new Ext.menu.Item({
                        text: t('add'),
                        iconCls: "opendxp_icon_add",
                        handler: this.onAddDataport.bind(this),
                    }));

                    menu.add(new Ext.menu.Item({
                        text: t('copy'),
                        iconCls: "opendxp_icon_copy",
                        handler: function () {
                            Ext.each(records, function (record) {
                                if (selectedRows.length === 1) {
                                    this.sourceConfigRawitemPanel.getSelectionModel().select(record);
                                } else {
                                    this.sourceConfigRawitemPanel.getSelectionModel().deselectAll();
                                }

                                var addedRecord = this.onAddDataport();
                                var name = record.get('name').replace(/(.*)(\d+)$/, function (input, namePrefix, trailingDigits) {
                                    return namePrefix + (parseInt(trailingDigits, 10) + 1);
                                });

                                addedRecord.set('name', name);

                                var column = record.get('cmd');
                                addedRecord.set('cmd', column);
                            }.bind(this));
                        }.bind(this)
                    }));

                    menu.add(new Ext.menu.Item({
                        text: t('delete'),
                        iconCls: "opendxp_icon_delete",
                        handler: function() {
                            this.onDeleteDataport(record);
                        }.bind(this)
                    }));

                    menu.showAt(e.getXY());

                    e.stopEvent();
                }.bind(this)
            },
            plugins: {
                ptype: 'cellediting',
                clicksToEdit: 2
            },
            viewConfig: {
                plugins: {
                    ptype: 'gridviewdragdrop',
                    pluginId: 'gridviewdragdrop',
                    dragText: t('pim.dataport.change-order-hint')
                }
            }
        });

        this.sourceConfigPanel.add(this.sourceConfigBasePanel, this.sourceConfigRawitemPanel);

        opendxp.layout.refresh();
    },

    renderObjectWizardSourceConfigPanel: function () {
        var additionalColumns = this.getColumnConfigForSourcetype('object-wizard');

        // Rohdatenfeldkonfiguration
        if (typeof ObjectWizardImportconfigModel == 'undefined') {
            var readerFields = [
                { name: 'fieldNo', allowBlank: false, type: 'integer' },
                { name: 'name', allowBlank: false }
            ];

            Ext.each(additionalColumns, function (column) {
                readerFields.push({ name: column.dataIndex });
            });
            readerFields.push({ name: 'definition' });

            Ext.define('ObjectWizardImportconfigModel', {
                extend: 'Ext.data.Model',
                fields: readerFields,
                idProperty: 'fieldNo',
                root: 'fields'
            });
        }

        var store = new Ext.data.JsonStore({
            model: 'ObjectWizardImportconfigModel',
            proxy: {
                type: 'ajax',
                url: '/admin/SylphenDataBridge/importconfig/get-rawitemfield-config/' + this.dataport.id,
                reader: {
                    type: 'json',
                    rootProperty: 'fields',
                    messageProperty: 'errorMessage'
                }

            },
            autoLoad: true
        });

        // Panel für Importkonfiguration
        this.sourceConfigBasePanel = new Ext.form.FormPanel({
            padding: 5,
            layout: {
                type: 'vbox',
                align: 'stretch'
            },
            fieldDefaults: {
                labelAlign: 'left',
                labelWidth: 200
            },
            items: [
                {
                    xtype: 'panel',
                    itemId: 'targetSettings',
                    title: t('settings') + ': ' + ((this.dataport.itemClass === "0" || this.dataportPanel.getForm().findField('itemClass').getValue() === "0") ? t('export') : t('import')),
                    layout: {
                        type: 'vbox',
                        align: 'stretch'
                    },
                    bodyPadding: '10 0 0 10',
                    items: [
                        {
                            xtype: 'textfield',
                            name: 'itemFolder',
                            hidden: this.dataport.itemClass === "OpenDxp\\Model\\Asset" || this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Asset" || this.dataport.itemClass === "0" || this.dataportPanel.getForm().findField('itemClass').getValue() === "0" || (this.sourceConfigBasePanel && this.sourceConfigBasePanel.getForm().findField('mode').getValue() == '2'),
                            fieldLabel: t('pim.dataport_targetfolder'),
                            cls: "input_drop_target",
                            inputAttrTpl: 'data-qtip="' + t('pim.dataport.folder.pimcore_or_filesystem') + '"',
                            value: this.dataport.itemFolder,
                            listeners: {
                                render: function (el) {
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return e.getTarget();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "object" || data.elementType == "document") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "object" || data.elementType == "document") {
                                                target.value = data.path;
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    el.getEl().on('dblclick', function () {
                                        var elementType = 'object';
                                        if (this.dataport.itemClass === "OpenDxp\\Model\\Asset" || this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Asset") {
                                            elementType = 'asset';
                                        } else if (this.dataport.itemClass === "OpenDxp\\Model\\Document\\Page" || this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Document\\Page") {
                                            elementType = 'document';
                                        }
                                        opendxp.helpers.openElement(el.getValue(), elementType);
                                    }.bind(this));
                                }.bind(this)
                            }
                        }, {
                            xtype: 'textfield',
                            name: 'masterDocument',
                            hidden: this.dataport.itemClass !== "OpenDxp\\Model\\Document\\Page" || this.dataportPanel.getForm().findField('itemClass').getValue() !== "OpenDxp\\Model\\Document\\Page",
                            fieldLabel: t('content_master_document'),
                            cls: "input_drop_target",
                            value: this.dataport.masterDocument,
                            listeners: {
                                render: function (el) {
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return this.getEl();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "document") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "document") {
                                                this.setValue(data.path);
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    this.getEl().on('dblclick', function () {
                                        opendxp.helpers.openElement(this.getValue(), 'document');
                                    }.bind(this));
                                }
                            }
                        }, {
                            xtype: 'textfield',
                            name: 'assetSource',
                            hidden: this.dataport.itemClass === "OpenDxp\\Model\\Asset" || this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Asset",
                            fieldLabel: t('pim.dataport.excel.assetSource'),
                            cls: "input_drop_target",
                            inputAttrTpl: 'data-qtip="' + t('pim.dataport.folder.pimcore_or_filesystem') + '"',
                            listeners: {
                                render: function (el) {
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return this.getEl();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                this.setValue(data.path);
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    this.getEl().on('dblclick', function () {
                                        opendxp.helpers.openElement(this.getValue(), 'asset');
                                    }.bind(this));
                                }
                            },
                            value: this.getSourceconfig('assetSource')
                        },
                        {
                            xtype: 'textfield',
                            name: 'assetFolder',
                            fieldLabel: t('pim.dataport_assetfolder'),
                            cls: "input_drop_target",
                            inputAttrTpl: 'data-qtip="' + t('pim.dataport.folder.pimcore_or_filesystem') + '"',
                            listeners: {
                                render: function (el) {
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return this.getEl();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                this.setValue(data.path);
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    this.getEl().on('dblclick', function () {
                                        opendxp.helpers.openElement(this.getValue(), 'asset');
                                    }.bind(this));
                                }
                            },
                            value: this.dataport.assetFolder
                        }
                    ]
                },
                {
                    xtype: 'panel',
                    collapsible: true,
                    collapsed: true,
                    title: t('pim.dataport_advancedOptions'),
                    cls: 'x-panel-header-light',
                    style: 'border: none',
                    bodyPadding: '10 0 0 10',
                    headerOverCls: 'x-fieldset-header-text-collapsible',
                    titleCollapse: true,
                    fieldDefaults: {
                        labelAlign: 'left',
                        labelWidth: 200
                    },
                    layout: {
                        type: 'vbox',
                        align: 'stretch'
                    },
                    items: [
                        {
                            xtype: 'combo',
                            name: 'mode',
                            fieldLabel: t('pim.dataport_mode'),
                            editable: false,
                            forceSelection: true,
                            store: [
                                ['3', t('pim.dataport_mode.create_and_edit')],
                                ['1', t('pim.dataport_mode.create_only')],
                                ['2', t('pim.dataport_mode.edit_only')]
                            ],
                            value: this.dataport.mode
                        },
                        {
                            xtype: 'checkbox',
                            name: 'compatibilityMode',
                            value: this.dataport.compatibilityMode,
                            fieldLabel: t('pim.compatibilityMode'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.compatibilityMode.tooltip')
                            }
                        },
                        {
                            xtype: 'combo',
                            name: 'javascriptEngine',
                            fieldLabel: t('pim.dataport_javascriptEngine'),
                            hidden: true,
                            editable: false,
                            triggerAction: 'all',
                            store: Ext.create('Ext.data.JsonStore', {
                                model: 'JavascriptEngineModel',
                                proxy: {
                                    type: 'ajax',
                                    url: '/admin/SylphenDataBridge/importconfig/get-javascript-engines',
                                    reader: {
                                        type: 'json',
                                        rootProperty: 'data'
                                    }

                                },
                                autoLoad: true
                            }),
                            valueField: 'id',
                            displayField: 'name',
                            value: this.dataport.javascriptEngine,
                            listeners: {
                                select: function (comp, record, index) {
                                    if (comp.getValue() == "" || comp.getValue() == "&nbsp;") {
                                        comp.setValue(null);
                                    }
                                }
                            }
                        },
                        {
                            xtype: 'checkbox',
                            name: 'optimizeInheritance',
                            value: this.dataport.optimizeInheritance,
                            fieldLabel: t('pim.dataport_optimize_inheritance'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport_optimize_inheritance.tooltip')
                            }
                        },
                        {
                            xtype: 'numberfield',
                            minValue: 0,
                            decimalPrecision: 0,
                            name: 'parallelProcesses',
                            value: this.dataport.parallelProcesses,
                            fieldLabel: t('pim.dataport_parallel_processes'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport_parallel_processes.tooltip')
                            }
                        },
                        {
                            xtype: 'checkbox',
                            name: 'skipVersioning',
                            fieldLabel: t('pim.dataport_skip_versioning'),
                            value: this.dataport.skipVersioning,
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport_skip_versioning.tooltip')
                            },
                            hidden: true
                        },
                        {
                            xtype: 'tagfield',
                            name: 'error_recipients[]',
                            fieldLabel: t('pim.dataport.send_error_notification'),
                            store: Ext.create('Ext.data.JsonStore', {
                                proxy: {
                                    type: 'ajax',
                                    url: '/admin/SylphenDataBridge/importconfig/get-recipients/' + this.dataport.id,
                                    fields: ['name', 'userId'],
                                    reader: {
                                        type: 'json',
                                        rootProperty: 'data'
                                    }

                                },
                                autoLoad: true
                            }),
                            value: this.dataport.errorRecipients,
                            valueField: 'userId',
                            displayField: 'name',
                            filterPickList: true,
                            forceSelection: true,
                            queryMode: 'local',
                            anyMatch: true
                        }
                    ]
                }
            ]
        });

        var columnConfig = [];
        columnConfig.push({
            header: "#",
            width: 45,
            dataIndex: 'fieldNo'
        });
        columnConfig.push({
            header: t('pim.dataport-fields-name'),
            width: 'auto',
            dataIndex: 'name',
            flex: 1,
            renderer: function (value, metaData, record) {
                if (!value) {
                    return '';
                }

                var mappingRecords = this.mappingPanel.getStore().queryBy(function () { return true; }).getRange();
                var isMapped = false;
                var minOneFieldIsMapped = false;
                Ext.each(mappingRecords, function (mappingRecord) {
                    if (mappingRecord.get('field')) {
                        minOneFieldIsMapped = true;
                    }

                    if (record.get('fieldNo') === mappingRecord.get('field')) {
                        isMapped = true;
                        return false;
                    }

                    var mappingRecordSettings = mappingRecord.get('settings');
                    if (mappingRecordSettings.calculation) {
                        minOneFieldIsMapped = true;
                        if (this.dataport.javascriptEngine === 'php' && mappingRecordSettings.calculation.indexOf('$params[\'rawItemData\'][\'' + value + '\']') > -1) {
                            isMapped = true;
                            return false;
                        } else if (this.dataport.javascriptEngine === 'v8js' && (mappingRecordSettings.calculation.indexOf('params[\'rawItemData\'][\'' + value + '\']') > -1 || mappingRecordSettings.calculation.indexOf('params.rawItemData.' + value) > -1)) {
                            isMapped = true;
                            return false;
                        }
                    }
                }.bind(this));

                if (!isMapped && minOneFieldIsMapped) {
                    metaData.tdAttr = 'data-qtip="' + t('pim.mapping.variables.variable.not_in_use') + '"';
                    return '<s>' + Ext.util.Format.htmlEncode(value) + '</s>';
                }
                return Ext.util.Format.htmlEncode(value);
            }.bind(this),
            editor: {
                xtype: 'textfield',
                allowBlank: false,
                handleMouseEvents: true,
                listeners: {
                    render: function (cmp) {
                        Ext.get(cmp.getInputId()).on('mousedown', function (e) {
                            e.stopPropagation();
                        });
                    }
                }
            }
        });

        Ext.each(additionalColumns, function (column) {
            columnConfig.push(column);
        });

        this.sourceConfigRawitemPanel = Ext.create('Ext.grid.Panel', {
            title: '',
            store: store,
            columns: {
                defaults: {
                    menuDisabled: true,
                    sortable: false
                },
                items: columnConfig
            },
            flex: 1,
            border: false,
            frame: false,
            autoScroll: true,
            layout: 'fit',
            minHeight: 300,
            selModel: {
                selType: 'rowmodel',
                mode: 'MULTI',
                ignoreRightMouseSelection: true
            },
            multiSelect: true,
            dockedItems: [{
                xtype: 'toolbar',
                dock: 'top',
                items: [
                    {
                        xtype: 'label',
                        html: t('pim.rawdatafields'),
                        cls: 'x-panel-header-title-default x-panel-header-default',
                        style: 'border:none;'
                    }, {
                        text: t('add'),
                        handler: this.onAddDataport.bind(this),
                        iconCls: "opendxp_icon_add"
                    },
                    '-',
                    {
                        text: t('delete'),
                        handler: function () {
                            this.onDeleteDataport();
                        }.bind(this),
                        iconCls: "opendxp_icon_delete"
                    },
                    '-',
                    {
                        text: t('pim.rawdatafields.bulk_edit'),
                        iconCls: "opendxp_icon_edit",
                        handler: function () {
                            var displayField = {
                                xtype: "displayfield",
                                region: "north",
                                hideLabel: true,
                                border: false,
                                value: t('pim.rawdatafields.bulk_edit.description') + ':<br>' + Ext.Array.filter(Ext.Array.map(columnConfig, function (column) {
                                    return column.dataIndex;
                                }), function (dataIndex) {
                                    return dataIndex !== 'data1';
                                }).join(',')
                            };

                            var data = [];
                            store.each(function (rec) {
                                var row = [];
                                Ext.Array.each(columnConfig, function (column) {
                                    if (column.dataIndex === 'data1') {
                                        return true;
                                    }
                                    row.push(rec.get(column.dataIndex));
                                });
                                data.push(row);
                            });

                            data = Ext.util.CSV.encode(data);

                            var textarea = new Ext.form.TextArea({
                                region: "center",
                                border: false,
                                value: data
                            });

                            var bulkEditWindow = new Ext.Window({
                                width: 800,
                                height: 500,
                                title: t('pim.rawdatafields.bulk_edit'),
                                iconCls: "opendxp_icon_edit",
                                layout: "fit",
                                modal: true,
                                resizable: true,
                                items: [new Ext.Panel({
                                    layout: "border",
                                    padding: '0 10',
                                    items: [displayField, textarea]
                                })],
                                buttons: [
                                    {
                                        text: t('apply'),
                                        iconCls: "opendxp_icon_save",
                                        handler: function () {
                                            store.removeAll();
                                            var content = textarea.getValue();
                                            if (content.length > 0) {
                                                var csvData = Ext.util.CSV.decode(content);

                                                for (var i = 0; i < csvData.length; i++) {
                                                    var row = csvData[i];

                                                    this.onAddDataport();
                                                    var record = this.sourceConfigRawitemPanel.getSelectionModel().getSelection()[0];

                                                    Ext.Array.each(columnConfig, function (column, index) {
                                                        record.set(column.dataIndex, row[index])
                                                    });
                                                }
                                            }

                                            bulkEditWindow.close();
                                        }.bind(this)
                                    },
                                    {
                                        text: t('cancel'),
                                        iconCls: "opendxp_icon_empty",
                                        handler: function () {
                                            bulkEditWindow.close();
                                        }
                                    }
                                ]
                            }).show();
                        }.bind(this)
                    }
                ]
            }],
            listeners: {
                beforeedit: function (editor, context) {
                    context.grid.getView().getPlugin('gridviewdragdrop').disable();
                },
                canceledit: function (editor, context) {
                    context.grid.getView().getPlugin('gridviewdragdrop').enable();
                },
                edit: function (editor, e) {
                    if (!e.record.get('name') && e.field === 'json-path' && e.value !== '') {
                        var isValid = true;

                        store.each(function (record) {
                            if (record.get('name') === e.value && record.get('fieldNo') !== e.record.get('fieldNo')) {
                                isValid = false;
                                return false;
                            }
                        });

                        if (isValid) {
                            e.record.set('name', e.value);
                        }
                    }

                    if(e.record.get('name')) {
                        var definition = e.record.get('definition');
                        if(!definition) {
                            if(!e.record.get('type')) {
                                e.record.set('type', 'input');
                            }

                            var editor = new opendxp.object.classes.data[e.record.get('type')](null, {
                                name: e.record.get('name'),
                                fieldtype: e.record.get('type'),
                                datatype: 'data'
                            });
                            editor.setInClassificationStoreEditor(true);
                            editor.getLayout();
                            editor.applyData();
                            e.record.set('definition', editor.getData());
                        } else {
                            definition.name = e.record.get('name');

                            var editor = new opendxp.object.classes.data[e.record.get('type')](null, definition);
                            editor.getLayout();
                            editor.setInClassificationStoreEditor(true);
                            editor.applyData();
                            e.record.set('definition', editor.getData());
                        }
                    }

                    this.onAddDataport(false);

                    e.grid.getView().getPlugin('gridviewdragdrop').enable();
                }.bind(this),
                validateedit: function (editor, e) {
                    var isValid = true;

                    store.each(function (record) {
                        if (e.value !== '' && record.get(e.field) === e.value && e.field === 'name' && e.record.get('fieldNo') !== record.get('fieldNo')) {
                            isValid = false;
                            return false;
                        }
                    });

                    if (!isValid) {
                        opendxp.helpers.showNotification(t("error"), t('pim.dataport-fields.duplicate'), "error");
                    }

                    return isValid;
                }.bind(this),
                celldblclick: function (grid, cell, cellIndex, record) {
                    if (columnConfig[cellIndex].dataIndex === 'data1') {
                        opendxp.helpers.copyStringToClipboard(record.get('data1'));
                        opendxp.helpers.showNotification(t("info"), t('pim.mapping.copied_to_clipboard_mapping'), 'info');
                    }
                },
                cellclick: function (grid, tdElement, cellIndex, record) {
                    if (columnConfig[cellIndex].dataIndex === 'fieldNo') {
                        if (record.get('sort') === 'ASC') {
                            record.set('sort', 'DESC');
                        } else {
                            record.set('sort', 'ASC');
                        }
                    }
                },
                itemcontextmenu: function (view, record, item, index, e) {
                    var menu = new Ext.menu.Menu();

                    var selectedRows = this.sourceConfigRawitemPanel.getSelectionModel().getSelection();
                    var clickedItemIsSelected = false;
                    if (selectedRows.length > 0) {
                        Ext.each(selectedRows, function (selectedRecord, index) {
                            if (record.get('fieldNo') === selectedRecord.get('fieldNo')) {
                                clickedItemIsSelected = true;
                                return false;
                            }
                        });
                    } else {
                        clickedItemIsSelected = true;
                    }

                    var records = [];
                    if (clickedItemIsSelected) {
                        records = selectedRows.reverse();
                    }

                    if (records.length === 0) {
                        records = selectedRows.length > 0 ? selectedRows : [record];
                    }

                    menu.add(new Ext.menu.Item({
                        text: t('pim.dataport.move-to-top'),
                        icon: "/bundles/opendxpadmin/img/flat-color-icons/up.svg",
                        handler: function (menuItem, e) {
                            Ext.each(records, function (record) {
                                store.data.remove(record);
                                store.data.insert(0, record);
                            });
                        }.bind(this),
                    }));

                    if (!clickedItemIsSelected) {
                        menu.add(new Ext.menu.Item({
                            text: t('pim.dataport.move-to-selection').replace('%s', selectedRows[selectedRows.length - 1].get('name')),
                            iconCls: "opendxp_icon_table_row",
                            handler: function (menuItem, e) {
                                store.data.remove(record);
                                store.data.insert(store.indexOf(selectedRows[selectedRows.length - 1]) + 1, record);
                            }.bind(this),
                        }));
                    }

                    if (record.get('sort') === null || record.get('sort') === 'ASC') {
                        menu.add(new Ext.menu.Item({
                            text: t('pim.dataport.sort-descending'),
                            icon: "/bundles/opendxpadmin/img/flat-color-icons/alphabetical_sorting_za.svg",
                            handler: function (menuItem, e) {
                                Ext.each(records, function (record) {
                                    record.set('sort', 'DESC');
                                });
                            }.bind(this),
                        }));
                    } else if (record.get('sort') === 'DESC') {
                        menu.add(new Ext.menu.Item({
                            text: t('pim.dataport.sort-ascending'),
                            icon: "/bundles/opendxpadmin/img/flat-color-icons/alphabetical_sorting_az.svg",
                            handler: function (menuItem, e) {
                                Ext.each(records, function (record) {
                                    record.set('sort', 'ASC');
                                });
                            }.bind(this),
                        }));
                    }

                    menu.add(new Ext.menu.Item({
                        text: t('add'),
                        iconCls: "opendxp_icon_add",
                        handler: this.onAddDataport.bind(this),
                    }));

                    menu.add(new Ext.menu.Item({
                        text: t('copy'),
                        iconCls: "opendxp_icon_copy",
                        handler: function () {
                            Ext.each(records, function (record) {
                                if (selectedRows.length === 1) {
                                    this.sourceConfigRawitemPanel.getSelectionModel().select(record);
                                } else {
                                    this.sourceConfigRawitemPanel.getSelectionModel().deselectAll();
                                }

                                var addedRecord = this.onAddDataport();
                                var name = record.get('name').replace(/(.*)(\d+)$/, function (input, namePrefix, trailingDigits) {
                                    return namePrefix + (parseInt(trailingDigits, 10) + 1);
                                });

                                addedRecord.set('name', name);
                                addedRecord.set('type', record.get('type'));
                                addedRecord.set('definition', record.get('definition'));
                            }.bind(this));
                        }.bind(this)
                    }));

                    menu.add(new Ext.menu.Item({
                        text: t('delete'),
                        iconCls: "opendxp_icon_delete",
                        handler: function () {
                            this.onDeleteDataport(record);
                        }.bind(this)
                    }));

                    menu.showAt(e.getXY());

                    e.stopEvent();
                }.bind(this)
            },
            plugins: {
                ptype: 'cellediting',
                clicksToEdit: 2
            },
            viewConfig: {
                plugins: {
                    ptype: 'gridviewdragdrop',
                    pluginId: 'gridviewdragdrop',
                    dragText: t('pim.dataport.change-order-hint')
                }
            }
        });

        this.sourceConfigPanel.add(this.sourceConfigBasePanel, this.sourceConfigRawitemPanel);

        opendxp.layout.refresh();
    },



    renderFixedLengthFileSourceConfigPanel: function () {
        var additionalColumns = this.getColumnConfigForSourcetype('fixed-length');

        // Rohdatenfeldkonfiguration
        if (typeof FixedLengthImportconfigModel == 'undefined') {
            var readerFields = [
                {name: 'fieldNo', allowBlank: false, type: 'integer'},
                { name: 'sort', allowBlank: false },
                {name: 'name', allowBlank: false}
            ];

            Ext.each(additionalColumns, function (column) {
                readerFields.push({name: column.dataIndex});
            });

            readerFields.push({name: 'data1', persist: false});

            Ext.define('FixedLengthImportconfigModel', {
                    extend: 'Ext.data.Model',
                    fields: readerFields,
                    idProperty: 'fieldNo',
                    root: 'fields'
                }
            );
        }

        var store = new Ext.data.JsonStore({
            model: 'FixedLengthImportconfigModel',
            proxy: {
                type: 'ajax',
                url: '/admin/SylphenDataBridge/importconfig/get-rawitemfield-config/' + this.dataport.id,
                reader: {
                    type: 'json',
                    rootProperty: 'fields',
                    messageProperty: 'errorMessage'
                }
            },
            listeners: {
                load: function () {
                    Ext.Ajax.request({
                        url: '/admin/SylphenDataBridge/importconfig/get-demo-data',
                        params: {
                            dataportId: this.dataportId
                        },
                        success: function (response) {
                            response = Ext.decode(response.responseText);

                            var store = this.sourceConfigRawitemPanel.getStore();
                            Ext.each(response.data, function (value) {
                                var record = store.findRecord('fieldNo', value.fieldNo, 0, false, true, true)

                                if (record) {
                                    record.set('data1', value.value);
                                }
                            }.bind(this));

                            this.sourceConfigRawitemPanel.getView().refresh();
                        }.bind(this)
                    });
                }.bind(this)
            },
            autoLoad: true
        });


        var columnConfig = [];
        columnConfig.push({
            header: "#",
            width: 50,
            dataIndex: 'fieldNo',
            renderer: function (value, metaData, record) {
                if (record.get('sort') === 'DESC') {
                    metaData.tdAttr = 'data-qtip="' + t('pim.dataport-fields.sorting-direction.desc') + '"';
                    value += ' <img src="/bundles/opendxpadmin/img/flat-color-icons/alphabetical_sorting_za.svg" height="18" width="18" style="height:18px;vertical-align:middle">';
                } else {
                    metaData.tdAttr = 'data-qtip="' + t('pim.dataport-fields.sorting-direction.asc') + '"';
                }
                return value;
            }
        });
        columnConfig.push({
            header: t('pim.dataport-fields-name'),
            dataIndex: 'name',
            flex: 1,
            width: 'auto',
            renderer: function (value, metaData, record) {
                if (!value) {
                    return '';
                }

                var mappingRecords = this.mappingPanel.getStore().queryBy(function () { return true; }).getRange();
                var isMapped = false;
                var minOneFieldIsMapped = false;
                Ext.each(mappingRecords, function (mappingRecord) {
                    if (mappingRecord.get('field')) {
                        minOneFieldIsMapped = true;
                    }

                    if (record.get('fieldNo') === mappingRecord.get('field')) {
                        isMapped = true;
                        return false;
                    }

                    var mappingRecordSettings = mappingRecord.get('settings');
                    if (mappingRecordSettings.calculation) {
                        minOneFieldIsMapped = true;
                        if (this.dataport.javascriptEngine === 'php' && mappingRecordSettings.calculation.indexOf('$params[\'rawItemData\'][\'' + value + '\']') > -1) {
                            isMapped = true;
                            return false;
                        } else if (this.dataport.javascriptEngine === 'v8js' && (mappingRecordSettings.calculation.indexOf('params[\'rawItemData\'][\'' + value + '\']') > -1 || mappingRecordSettings.calculation.indexOf('params.rawItemData.' + value) > -1)) {
                            isMapped = true;
                            return false;
                        }
                    }
                }.bind(this));

                if (!isMapped && minOneFieldIsMapped) {
                    metaData.tdAttr = 'data-qtip="' + t('pim.mapping.variables.variable.not_in_use') + '"';
                    return '<s>' + Ext.util.Format.htmlEncode(value) + '</s>';
                }
                return Ext.util.Format.htmlEncode(value);
            }.bind(this),
            editor: {
                xtype: 'textfield',
                allowBlank: false,
                handleMouseEvents: true,
                listeners: {
                    render: function (cmp) {
                        Ext.get(cmp.getInputId()).on('mousedown', function (e) {
                            e.stopPropagation();
                        });
                    }
                }
            }
        });

        Ext.each(additionalColumns, function (column) {
            columnConfig.push(column);
        });

        columnConfig.push({
            header: t('pim.dataport-fields-ex1'),
            dataIndex: 'data1',
            flex: 1,
            renderer: function (value, metaData, record) {
                if (typeof value === 'undefined') {
                    return '';
                }
                return Ext.util.Format.htmlEncode(value);
            }
        });

        var autoCreate = function (response) {
            response = Ext.decode(response.responseText);

            if (typeof response.config !== 'undefined') {
                var store = this.sourceConfigRawitemPanel.getStore();
                Ext.each(response.config.fields, function (field) {
                    if (store.findExact('name', field.name) === -1) {
                        this.onAddDataport();
                        var record = this.sourceConfigRawitemPanel.getSelectionModel().getSelection()[0];
                        record.set({
                            name: field.name,
                            "length": field["length"]
                        });
                    }
                }.bind(this));
            } else {
                var uploadWindow = Ext.create('Ext.Window', {
                    layout: {
                        type: 'vbox',
                        align: 'stretch'
                    },
                    title: t('pim.dataport.auto_create.window.title'),
                    width: 500,
                    height: 300,
                    modal: true,
                    items: Ext.create('Ext.form.Panel', {
                        padding: 15,
                        itemId: 'uploadForm',
                        layout: {
                            type: 'vbox',
                            align: 'stretch'
                        },
                        items: [{
                            xtype: 'fileuploadfield',
                            allowBlank: false,
                            fieldLabel: t('pim.manual.importForm.uploadLabel'),
                            name: 'file',
                            buttonText: t('select_a_file'),
                            buttonCfg: {
                                iconCls: 'opendxp_icon_file'
                            },
                        }, {
                            xtype: 'hidden',
                            name: 'sourceType',
                            value: this.dataportPanel.getForm().findField('sourcetype').getValue(),
                        }, {
                            xtype: 'hidden',
                            name: 'dataportId',
                            value: this.dataportId,
                        }, {
                            xtype: 'hidden',
                            name: 'csrfToken',
                            value: opendxp.settings['csrfToken'],
                        }]
                    }),
                    buttons: [{
                        text: t('pim.dataport.auto_create.window.button'),
                        handler: function () {
                            if (uploadWindow.queryById('uploadForm').getForm().isValid()) {
                                uploadWindow.queryById('uploadForm').getForm().submit({
                                    url: '/admin/SylphenDataBridge/importconfig/auto-create-rawdata-fields',
                                    waitMsg: t('pim.manual.importForm.waitMsg'),
                                    success: function (fp, response) {
                                        if (uploadWindow) {
                                            uploadWindow.close();
                                        }

                                        autoCreate(response.response);
                                    }.bind(this),
                                    failure: function (form, action) {
                                        if (action.result.msg) {
                                            Ext.MessageBox.alert(t('error'), t('pim.manual.importForm.failure') + action.result.msg);
                                        }
                                    }.bind(this)
                                });
                            }
                        }.bind(this)
                    }]
                });
                uploadWindow.show();
            }
        }.bind(this);

        this.sourceConfigRawitemPanel = Ext.create('Ext.grid.Panel', {
            title: '',
            store: store,
            columns: {
                defaults: {
                    menuDisabled : true,
                    sortable: false
                },
                items: columnConfig
            },
            flex: 1,
            border: false,
            frame: false,
            autoScroll: true,
            layout: 'fit',
            minHeight:300,
            selModel: {
                selType: 'rowmodel',
                mode: 'MULTI',
                ignoreRightMouseSelection: true
            },
            multiSelect: true,
            dockedItems: [{
                xtype: 'toolbar',
                dock: 'top',
                items: [
                    {
                        xtype: 'label',
                        html: t('pim.rawdatafields'),
                        cls: 'x-panel-header-title-default x-panel-header-default',
                        style: 'border:none;'
                    },
                    {
                        text: t('add'),
                        handler: this.onAddDataport.bind(this),
                        iconCls: "opendxp_icon_add"
                    },
                    '-',
                    {
                        text: t('delete'),
                        handler: function () {
                            this.onDeleteDataport();
                        }.bind(this),
                        iconCls: "opendxp_icon_delete"
                    },
                    '-',
                    {
                        text: t('pim.rawdatafields.bulk_edit'),
                        iconCls: "opendxp_icon_edit",
                        handler: function () {
                            var displayField = {
                                xtype: "displayfield",
                                region: "north",
                                hideLabel: true,
                                border: false,
                                value: t('pim.rawdatafields.bulk_edit.description') + ':<br>' + Ext.Array.filter(Ext.Array.map(columnConfig, function (column) {
                                    return column.dataIndex;
                                }), function (dataIndex) {
                                    return dataIndex !== 'data1';
                                }).join(',')
                            };

                            var data = [];
                            store.each(function (rec) {
                                var row = [];
                                Ext.Array.each(columnConfig, function (column) {
                                    if (column.dataIndex === 'data1') {
                                        return true;
                                    }
                                    row.push(rec.get(column.dataIndex));
                                });
                                data.push(row);
                            });

                            data = Ext.util.CSV.encode(data);

                            var textarea = new Ext.form.TextArea({
                                region: "center",
                                border: false,
                                value: data
                            });

                            var bulkEditWindow = new Ext.Window({
                                width: 800,
                                height: 500,
                                title: t('pim.rawdatafields.bulk_edit'),
                                iconCls: "opendxp_icon_edit",
                                layout: "fit",
                                modal: true,
                                resizable: true,
                                items: [new Ext.Panel({
                                    layout: "border",
                                    padding: '0 10',
                                    items: [displayField, textarea]
                                })],
                                buttons: [
                                    {
                                        text: t('apply'),
                                        iconCls: "opendxp_icon_save",
                                        handler: function () {
                                            store.removeAll();
                                            var content = textarea.getValue();
                                            if (content.length > 0) {
                                                var csvData = Ext.util.CSV.decode(content);

                                                for (var i = 0; i < csvData.length; i++) {
                                                    var row = csvData[i];

                                                    this.onAddDataport();
                                                    var record = this.sourceConfigRawitemPanel.getSelectionModel().getSelection()[0];

                                                    Ext.Array.each(columnConfig, function (column, index) {
                                                        record.set(column.dataIndex, row[index])
                                                    });
                                                }
                                            }

                                            bulkEditWindow.close();
                                        }.bind(this)
                                    },
                                    {
                                        text: t('cancel'),
                                        iconCls: "opendxp_icon_empty",
                                        handler: function () {
                                            bulkEditWindow.close();
                                        }
                                    }
                                ]
                            }).show();
                        }.bind(this)
                    },
                    '->',
                    {
                        text: t('pim.dataport.auto_create'),
                        handler: function() {
                            Ext.Ajax.request({
                                url: '/admin/SylphenDataBridge/importconfig/auto-create-rawdata-fields',
                                method: 'post',
                                params: {
                                    sourceType: this.dataportPanel.getForm().findField('sourcetype').getValue(),
                                    file: this.sourceConfigBasePanel.getForm().findField('file').getValue(),
                                    dataportId: this.dataportId
                                },
                                success: autoCreate
                            });
                        }.bind(this),
                        iconCls: "opendxp_icon_clear_cache"
                    }]
            }],

            listeners: {
                beforeedit: function(editor, context) {
                    context.grid.getView().getPlugin('gridviewdragdrop').disable();
                },
                canceledit: function(editor, context) {
                    context.grid.getView().getPlugin('gridviewdragdrop').enable();
                },
                edit: function (editor, e) {
                    if (!e.record.get('name') && e.field === 'column' && e.value !== '') {
                        var isValid = true;

                        store.each(function (record) {
                            if (record.get('name') === e.value && record.get('fieldNo') !== e.record.get('fieldNo')) {
                                isValid = false;
                                return false;
                            }
                        });

                        if (isValid) {
                            e.record.set('name', e.value);
                        }
                    }

                    this.onAddDataport(false);

                    e.grid.getView().getPlugin('gridviewdragdrop').enable();
                }.bind(this),
                validateedit: function (editor, e) {
                    var isValid = true;

                    store.each(function (record) {
                        if (e.value !== '' && record.get(e.field) === e.value && e.record.get('fieldNo') !== record.get('fieldNo')) {
                            isValid = false;
                            return false;
                        }
                    });

                    if (!isValid) {
                        opendxp.helpers.showNotification(t("error"), t('pim.dataport-fields.duplicate'), "error");
                    }

                    return isValid;
                }.bind(this),
                celldblclick: function (grid, cell, cellIndex, record) {
                    if (columnConfig[cellIndex].dataIndex === 'data1') {
                        opendxp.helpers.copyStringToClipboard(record.get('data1'));
                        opendxp.helpers.showNotification(t("info"), t('pim.mapping.copied_to_clipboard_mapping'), 'info');
                    }
                },
                cellclick: function (grid, tdElement, cellIndex, record) {
                    if (columnConfig[cellIndex].dataIndex === 'fieldNo') {
                        if (record.get('sort') === 'ASC') {
                            record.set('sort', 'DESC');
                        } else {
                            record.set('sort', 'ASC');
                        }
                    }
                },
                itemcontextmenu: function(view, record, item, index, e) {
                    var menu = new Ext.menu.Menu();

                    var selectedRows = this.sourceConfigRawitemPanel.getSelectionModel().getSelection();
                    var clickedItemIsSelected = false;
                    if(selectedRows.length > 0) {
                        Ext.each(selectedRows, function (selectedRecord, index) {
                            if (record.get('fieldNo') === selectedRecord.get('fieldNo')) {
                                clickedItemIsSelected = true;
                                return false;
                            }
                        });
                    } else {
                        clickedItemIsSelected = true;
                    }

                    var records = [];
                    if (clickedItemIsSelected) {
                        records = selectedRows.reverse();
                    }

                    if (records.length === 0) {
                        records = selectedRows.length > 0 ? selectedRows : [record];
                    }

                    menu.add(new Ext.menu.Item({
                        text: t('pim.dataport.move-to-top'),
                        icon: "/bundles/opendxpadmin/img/flat-color-icons/up.svg",
                        handler: function(menuItem, e) {
                            Ext.each(records, function (record) {
                                store.data.remove(record);
                                store.data.insert(0, record);
                            });
                        }.bind(this),
                    }));

                    if(!clickedItemIsSelected) {
                        menu.add(new Ext.menu.Item({
                            text: t('pim.dataport.move-to-selection').replace('%s', selectedRows[selectedRows.length - 1].get('name')),
                            iconCls: "opendxp_icon_table_row",
                            handler: function (menuItem, e) {
                                store.data.remove(record);
                                store.data.insert(store.indexOf(selectedRows[selectedRows.length - 1]) + 1, record);
                            }.bind(this),
                        }));
                    }

                    if (record.get('sort') === null || record.get('sort') === 'ASC') {
                        menu.add(new Ext.menu.Item({
                            text: t('pim.dataport.sort-descending'),
                            icon: "/bundles/opendxpadmin/img/flat-color-icons/alphabetical_sorting_za.svg",
                            handler: function (menuItem, e) {
                                Ext.each(records, function (record) {
                                    record.set('sort', 'DESC');
                                });
                            }.bind(this),
                        }));
                    } else if (record.get('sort') === 'DESC') {
                        menu.add(new Ext.menu.Item({
                            text: t('pim.dataport.sort-ascending'),
                            icon: "/bundles/opendxpadmin/img/flat-color-icons/alphabetical_sorting_az.svg",
                            handler: function (menuItem, e) {
                                Ext.each(records, function (record) {
                                    record.set('sort', 'ASC');
                                });
                            }.bind(this),
                        }));
                    }

                    menu.add(new Ext.menu.Item({
                        text: t('add'),
                        iconCls: "opendxp_icon_add",
                        handler: this.onAddDataport.bind(this),
                    }));

                    menu.add(new Ext.menu.Item({
                        text: t('copy'),
                        iconCls: "opendxp_icon_copy",
                        handler: function() {
                            Ext.each(records, function (record) {
                                if (selectedRows.length === 1) {
                                    this.sourceConfigRawitemPanel.getSelectionModel().select(record);
                                } else {
                                    this.sourceConfigRawitemPanel.getSelectionModel().deselectAll();
                                }

                                var addedRecord = this.onAddDataport();
                                var name = record.get('name').replace(/(.*)(\d+)$/, function (input, namePrefix, trailingDigits) {
                                    return namePrefix + (parseInt(trailingDigits, 10) + 1);
                                });

                                addedRecord.set('name', name);

                                var column = record.get('column');
                                addedRecord.set('column', column);
                            }.bind(this));
                        }.bind(this)
                    }));

                    menu.add(new Ext.menu.Item({
                        text: t('delete'),
                        iconCls: "opendxp_icon_delete",
                        handler: function () {
                            this.onDeleteDataport(record);
                        }.bind(this)
                    }));

                    menu.showAt(e.getXY());

                    e.stopEvent();
                }.bind(this)
            },
            plugins: {
                ptype: 'cellediting',
                clicksToEdit: 2
            },
            viewConfig: {
                plugins: {
                    ptype: 'gridviewdragdrop',
                    pluginId: 'gridviewdragdrop',
                    dragText: t('pim.dataport.change-order-hint')
                }
            }
        });

        var fileInput = Ext.create('Ext.form.field.TextArea', {
            name: 'file',
            fieldLabel: t('pim.dataport.file'),
            value: this.getSourceconfig('file'),
            hidden: true,
            fieldStyle: 'background: url(/bundles/opendxpadmin/img/flat-color-icons/target.svg) right 5px center/20px no-repeat transparent !important;'
        });

        var editorId = Ext.id();
        var editor;
        var fieldConfig = {
            fieldLabel: t('pim.dataport.file'),
            name: 'fileEditor',
            value: '<div id="' + editorId + '" style="height:14px;width:100%;line-height:21px;background: url(/bundles/opendxpadmin/img/flat-color-icons/target.svg) right 5px center/20px no-repeat transparent !important;"></div>',
            fieldCls: 'x-form-text-default x-form-trigger-wrap-default',
            fieldStyle: 'padding-right: 0',
            style: 'opacity: 1',
            listeners: {
                render: function (el) {
                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                        ddGroup: "element",

                        getTargetFromEvent: function (e) {
                            return this.getEl();
                        },

                        onNodeOver: function (target, dd, e, data) {
                            data = data.records[0].data;

                            if (data.elementType == "asset") {
                                return Ext.dd.DropZone.prototype.dropAllowed;
                            }
                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                        },

                        onNodeDrop: function (target, dd, e, data) {
                            data = data.records[0].data;

                            if (data.elementType == "asset") {
                                editor.setValue(data.path);
                                return true;
                            }
                            return false;
                        }.bind(this)
                    });

                    this.getEl().on('dblclick', function () {
                        if (editor.getValue().length < 512) {
                            Ext.Ajax.request({
                                url: '/admin/element/get-subtype',
                                params: {
                                    id: editor.getValue(),
                                    type: 'asset'
                                },
                                success: function (response) {
                                    var res = Ext.decode(response.responseText);
                                    if (res.success) {
                                        opendxp.helpers.openElement(res.id, res.type, res.subtype);
                                    }
                                }
                            });
                        }
                    }.bind(this));
                },
                afterrender: function (cmp) {
                    editor = ace.edit(editorId);
                    editor.setTheme('ace/theme/chrome');
                    if (fileInput.getValue().indexOf("{{") === -1 && fileInput.getValue().indexOf("{%") === -1 && fileInput.getValue().indexOf("\n") > -1) {
                        editor.session.setMode('ace/mode/sh');
                    } else {
                        editor.session.setMode('ace/mode/twig');
                    }

                    var languageTools = ace.require("ace/ext/language_tools");
                    editor.completers = [languageTools.snippetCompleter, languageTools.textCompleter, {
                        getCompletions: function (editor, session, pos, prefix, callback) {
                            var variables = [];

                            var mappingRecords = this.mappingPanel.getStore().queryBy(function () { return true; }).getRange();
                            Ext.each(mappingRecords, function (mappingRecord) {
                                if(['__result_callback', '__result_action', '__init_action'].indexOf(mappingRecord.get('attributeKey')) > -1 || mappingRecord.get('attributeKey').indexOf('__virtual_') > -1) {
                                    return true;
                                }
                                var name = '';
                                if(mappingRecord.get('attributeKey').toLowerCase() !== mappingRecord.get('attributeName').toLowerCase()) {
                                    name = ' -> '+ mappingRecord.get('attributeName');
                                }
                                variables.push({ caption: '{{ '+ mappingRecord.get('attributeKey')+' }}'+name, value: mappingRecord.get('attributeKey') +' }}' });
                            });

                            variables.push({ caption: 'SFTP', value: 'sftp://user:password@hostname/path/to/files' });
                            variables.push({ caption: 'FTP', value: 'ftp://user:password@hostname/path/to/files' });
                            variables.push({ caption: 'FTPS', value: 'ftps://user:password@hostname/path/to/files' });
                            variables.push({ caption: t('pim.dataport.file.suggest.http_basic_auth'), value: 'https://user:password@example.org/import-file' });
                            variables.push({
                                caption: t('pim.dataport.file.suggest.http_post'), value: 'curl -X POST\n' +
                                    '  --header \'Content-Type: application/json\'\n' +
                                    '  --data \'{ "param1": "value1", "param2":"value2"}\' \n' +
                                    '  https://example.org'
                            });
                            variables.push({ caption: 'AWS S3', value: 's3://key:secret@region/bucket/path/to/files -- '+t('pim.dataport.file.suggest.aws.hint') });

                            callback(null, variables);
                        }.bind(this)
                    }];
                    editor.setOptions({
                        showLineNumbers: false,
                        showGutter: false,
                        indentedSoftWrap: false,
                        showPrintMargin: false,
                        wrap: true,
                        //fontFamily: 'Open Sans, Helvetica Neue, helvetica, arial, verdana, sans-serif',
                        fontSize: "13px",
                        enableBasicAutocompletion: true,
                        enableSnippets: true,
                        enableLiveAutocompletion: true,
                        highlightActiveLine: false,
                        maxLines: 100
                    });

                    editor.setValue(fileInput.getValue() || '');
                    editor.clearSelection();

                    editor.on('blur', function() {
                        editor.clearSelection();
                    });
                    editor.on('change', function () {
                        fileInput.setValue(editor.getValue());
                        editorContainer.updateLayout();
                    }.bind(this));

                }.bind(this)
            }
        };
        var editorContainer = Ext.create("Ext.form.field.Display", fieldConfig);

        // Panel für Importkonfiguration
        this.sourceConfigBasePanel = new Ext.form.FormPanel({
            padding: 5,
            layout: {
                type: 'vbox',
                align: 'stretch'
            },
            fieldDefaults: {
                labelAlign: 'left',
                labelWidth: 200
            },
            items: [
                {
                    xtype: 'panel',
                    title: t('settings') + ': ' + this.dataportPanel.getForm().findField('sourcetype').getStore().findRecord("code", this.dataportPanel.getForm().findField('sourcetype').getValue()).get("label"),
                    layout: {
                        type: 'vbox',
                        align: 'stretch'
                    },
                    bodyPadding: '10 0 0 10',
                    items: [
                        fileInput,
                        editorContainer
                    ]
                },
                {
                    xtype: 'panel',
                    itemId: 'targetSettings',
                    title: t('settings') + ': ' +((this.dataport.itemClass === "0" || this.dataportPanel.getForm().findField('itemClass').getValue() === "0") ? t('export') : t('import')),
                    layout: {
                        type: 'vbox',
                        align: 'stretch'
                    },
                    bodyPadding: '10 0 0 10',
                    items: [
                        {
                            xtype: 'textfield',
                            name: 'itemFolder',
                            hidden: this.dataport.itemClass === "OpenDxp\\Model\\Asset" || this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Asset" || this.dataport.itemClass === "0" || this.dataportPanel.getForm().findField('itemClass').getValue() === "0" || (this.sourceConfigBasePanel && this.sourceConfigBasePanel.getForm().findField('mode').getValue() == '2'),
                            fieldLabel: t('pim.dataport_targetfolder'),
                            cls: "input_drop_target",
                            inputAttrTpl: 'data-qtip="' + t('pim.dataport.folder.pimcore_or_filesystem') + '"',
                            value: this.dataport.itemFolder,
                            listeners: {
                                render: function (el) {
                                    var me = this;
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return e.getTarget();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType === "object" || data.elementType === "document") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType === "object" || data.elementType === "document") {
                                                target.value = data.path;
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    el.getEl().on('dblclick', function () {
                                        var elementType = 'object';
                                        if (this.dataport.itemClass === "OpenDxp\\Model\\Asset" || this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Asset") {
                                            elementType = 'asset';
                                        } else if (this.dataport.itemClass === "OpenDxp\\Model\\Document\\Page" || this.dataportPanel.getForm().findField('itemClass').getValue() === "OpenDxp\\Model\\Document\\Page") {
                                            elementType = 'document';
                                        }
                                        opendxp.helpers.openElement(el.getValue(), elementType);
                                    }.bind(this));
                                }.bind(this)
                            }
                        },
                        {
                            xtype: 'textfield',
                            name: 'masterDocument',
                            hidden: this.dataport.itemClass !== "OpenDxp\\Model\\Document\\Page" || this.dataportPanel.getForm().findField('itemClass').getValue() !== "OpenDxp\\Model\\Document\\Page",
                            fieldLabel: t('content_master_document'),
                            cls: "input_drop_target",
                            value: this.dataport.masterDocument,
                            listeners: {
                                render: function (el) {
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return this.getEl();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "document") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "document") {
                                                this.setValue(data.path);
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    this.getEl().on('dblclick', function () {
                                        opendxp.helpers.openElement(this.getValue(), 'document');
                                    }.bind(this));
                                }
                            }
                        },
                        {
                            xtype: 'textfield',
                            name: 'assetSource',
                            fieldLabel: t('pim.dataport.csv.assetSource'),
                            cls: "input_drop_target",
                            listeners: {
                                render: function (el) {
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return this.getEl();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                this.setValue(data.path);
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    this.getEl().on('dblclick', function () {
                                        opendxp.helpers.openElement(this.getValue(), 'asset');
                                    }.bind(this));
                                }
                            },
                            value: this.getSourceconfig('assetSource')
                        },
                        {
                            xtype: 'textfield',
                            name: 'assetFolder',
                            fieldLabel: t('pim.dataport_assetfolder'),
                            cls: "input_drop_target",
                            inputAttrTpl: 'data-qtip="' + t('pim.dataport.folder.pimcore_or_filesystem') + '"',
                            listeners: {
                                render: function (el) {
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return this.getEl();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                this.setValue(data.path);
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    this.getEl().on('dblclick', function () {
                                        opendxp.helpers.openElement(this.getValue(), 'asset');
                                    }.bind(this));
                                }
                            },
                            value: this.dataport.assetFolder
                        },
                        {
                            xtype: 'checkbox',
                            name: 'autoImport',
                            fieldLabel: t('pim.dataport.auto-import'),
                            value: this.getSourceconfig('autoImport'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': (this.dataport.itemClass === "0" || this.dataportPanel.getForm().findField('itemClass').getValue() === "0") ? t('pim.dataport.auto-import.tooltip.export') : t('pim.dataport.auto-import.tooltip')
                            },
                            listeners: {
                                change: function (el, newValue) {
                                    if (!newValue) {
                                        this.sourceConfigBasePanel.getForm().findField('incrementalExport').hide();
                                    } else if (this.dataportPanel.getForm().findField('itemClass').getValue() === '0') {
                                        this.sourceConfigBasePanel.getForm().findField('incrementalExport').show();
                                    }
                                }.bind(this)
                            }
                        },
                        {
                            xtype: 'checkbox',
                            name: 'incrementalExport',
                            fieldLabel: t('pim.dataport.incremental_export'),
                            value: this.getSourceconfig('incrementalExport'),
                            hidden: this.dataport.itemClass !== "0" || this.dataportPanel.getForm().findField('itemClass').getValue() !== "0",
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport.incremental_export.tooltip')
                            }
                        },
                        {
                            xtype: 'textfield',
                            name: 'archiveFolder',
                            fieldLabel: t('pim.dataport_archiveFolder'),
                            cls: "input_drop_target",
                            value: this.getSourceconfig('archiveFolder'),
                            listeners: {
                                render: function (el) {
                                    var dd = new Ext.dd.DropZone(el.getEl().dom, {
                                        ddGroup: "element",

                                        getTargetFromEvent: function (e) {
                                            return this.getEl();
                                        },

                                        onNodeOver: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                return Ext.dd.DropZone.prototype.dropAllowed;
                                            }
                                            return Ext.dd.DropZone.prototype.dropNotAllowed;
                                        },

                                        onNodeDrop: function (target, dd, e, data) {
                                            data = data.records[0].data;

                                            if (data.elementType == "asset" && data.type == "folder") {
                                                this.setValue(data.path);
                                                return true;
                                            }
                                            return false;
                                        }.bind(this)
                                    });

                                    this.getEl().on('dblclick', function () {
                                        opendxp.helpers.openElement(this.getValue(), 'asset');
                                    }.bind(this));
                                }
                            }
                        }
                    ]
                },
                {
                    xtype: 'panel',
                    collapsible: true,
                    collapsed: true,
                    title: t('pim.dataport_advancedOptions'),
                    cls: 'x-panel-header-light',
                    style: 'border: none',
                    bodyPadding: '10 0 0 10',
                    headerOverCls: 'x-fieldset-header-text-collapsible',
                    titleCollapse: true,
                    fieldDefaults: {
                        labelAlign: 'left',
                        labelWidth: 200
                    },
                    layout: {
                        type: 'vbox',
                        align: 'stretch'
                    },
                    items: [
                        {
                            xtype: 'combo',
                            name: 'mode',
                            fieldLabel: t('pim.dataport_mode'),
                            editable: false,
                            forceSelection: true,
                            store: [
                                ['3', t('pim.dataport_mode.create_and_edit')],
                                ['1', t('pim.dataport_mode.create_only')],
                                ['2', t('pim.dataport_mode.edit_only')]
                            ],
                            value: this.dataport.mode
                        },
                        {
                            xtype: 'checkbox',
                            name: 'compatibilityMode',
                            value: this.dataport.compatibilityMode,
                            fieldLabel: t('pim.compatibilityMode'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.compatibilityMode.tooltip')
                            }
                        },
                        {
                            xtype: 'combo',
                            name: 'javascriptEngine',
                            fieldLabel: t('pim.dataport_javascriptEngine'),
                            hidden: true,
                            editable: false,
                            triggerAction: 'all',
                            store: Ext.create('Ext.data.JsonStore', {
                                model: 'JavascriptEngineModel',
                                proxy: {
                                    type: 'ajax',
                                    url: '/admin/SylphenDataBridge/importconfig/get-javascript-engines',
                                    reader: {
                                        type: 'json',
                                        rootProperty: 'data'
                                    }

                                },
                                autoLoad: true
                            }),
                            valueField: 'id',
                            displayField: 'name',
                            value: this.dataport.javascriptEngine,
                            listeners: {
                                select: function (comp, record, index) {
                                    if (comp.getValue() == "" || comp.getValue() == "&nbsp;") {
                                        comp.setValue(null);
                                    }
                                }
                            }
                        },
                        {
                            xtype: 'checkbox',
                            name: 'optimizeInheritance',
                            value: this.dataport.optimizeInheritance,
                            fieldLabel: t('pim.dataport_optimize_inheritance'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport_optimize_inheritance.tooltip')
                            }
                        },
                        {
                            xtype: 'numberfield',
                            minValue: 0,
                            decimalPrecision: 0,
                            name: 'parallelProcesses',
                            value: this.dataport.parallelProcesses,
                            fieldLabel: t('pim.dataport_parallel_processes'),
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport_parallel_processes.tooltip')
                            }
                        },
                        {
                            xtype: 'checkbox',
                            name: 'skipVersioning',
                            fieldLabel: t('pim.dataport_skip_versioning'),
                            value: this.dataport.skipVersioning,
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.dataport_skip_versioning.tooltip')
                            },
                            hidden: true
                        }, {
                            xtype: 'textfield',
                            name: 'idPrefix',
                            value: this.dataport.idPrefix,
                            fieldLabel: t('pim.dataport_idprefix'),
                            allowBlank: true,
                            hidden: true
                        }, {
                            xtype: 'textfield',
                            name: 'categoryClass',
                            value: this.dataport.categoryClass,
                            fieldLabel: t('pim.dataport_categoryclass'),
                            allowBlank: true,
                            hidden: true
                        }, {
                            xtype: 'textfield',
                            name: 'fieldnameProducts',
                            value: this.dataport.fieldnameProducts,
                            fieldLabel: t('pim.dataport_fieldnameProducts'),
                            allowBlank: true,
                            hidden: true
                        },
                        {
                            xtype: 'tagfield',
                            name: 'error_recipients[]',
                            fieldLabel: t('pim.dataport.send_error_notification'),
                            store: Ext.create('Ext.data.JsonStore', {
                                proxy: {
                                    type: 'ajax',
                                    url: '/admin/SylphenDataBridge/importconfig/get-recipients/' + this.dataport.id,
                                    fields: ['name', 'userId'],
                                    reader: {
                                        type: 'json',
                                        rootProperty: 'data'
                                    }

                                },
                                autoLoad: true
                            }),
                            value: this.dataport.errorRecipients,
                            valueField: 'userId',
                            displayField: 'name',
                            filterPickList: true,
                            forceSelection: true,
                            queryMode: 'local',
                            anyMatch: true
                        }
                    ]
                }
            ]
        });

        this.sourceConfigPanel.add(this.sourceConfigBasePanel, this.sourceConfigRawitemPanel);

        opendxp.layout.refresh();
    },

    onAddDataport: function (atCurrentPosition = true) {
        if (this.sourceConfigRawitemPanel instanceof Ext.grid.Panel) {
            var store = this.sourceConfigRawitemPanel.getStore();

            var maxNum = 0;
            var newRecord;
            store.each(function(record,id){
                if(!record.get('name')) {
                    if(atCurrentPosition) {
                        this.sourceConfigRawitemPanel.getView().focusRow(record);
                        this.sourceConfigRawitemPanel.getSelectionModel().select(record);
                    }

                    newRecord = record;

                    maxNum = null;
                    return false;
                }
                if(maxNum < parseInt(record.get('fieldNo'))) {
                    maxNum = parseInt(record.get('fieldNo'));
                }
            }.bind(this));

            if(maxNum === null) {
                return newRecord;
            }

            var u = new store.model();
            u.set('fieldNo', maxNum + 1);

            var selectedRows = this.sourceConfigRawitemPanel.getSelectionModel().getSelection();
            if(selectedRows.length === 0 || !atCurrentPosition) {
                store.add(u);
            } else {
                var index = store.indexOf(selectedRows[selectedRows.length-1]);
                store.insert(index+1, u);
            }

            this.sourceConfigRawitemPanel.getView().focusRow(u);
            this.sourceConfigRawitemPanel.getSelectionModel().select(u);

            return u;
        }
    },


    onDeleteDataport: function (record) {
        if (this.sourceConfigRawitemPanel instanceof Ext.grid.Panel) {
            var rec = this.sourceConfigRawitemPanel.getSelectionModel().getSelection();

            if(record) {
                var clickedRecordIsSelected = false;
                Ext.each(rec, function(selectedRecord) {
                    if(selectedRecord === record) {
                        clickedRecordIsSelected = true;
                        return false;
                    }
                });
            } else {
                clickedRecordIsSelected = true;
            }

            if(!clickedRecordIsSelected) {
                rec = [record];
            }

            var store = this.sourceConfigRawitemPanel.getStore();
            var index = store.indexOf(rec[rec.length-1]);
            var focusRecord = store.getAt(index+1);
            store.remove(rec);
            if(focusRecord === null) {
                focusRecord = store.getAt(store.getCount()-1);
            }

            if(focusRecord !== null) {
                this.sourceConfigRawitemPanel.getView().focusRow(focusRecord);
                this.sourceConfigRawitemPanel.getSelectionModel().select(focusRecord);
            }
        }
    },


    getSourceconfig: function (key) {
        if (this.dataport.sourceconfig && key in this.dataport.sourceconfig) {
            return this.dataport.sourceconfig[key];
        }

        return null;
    },

    getColumnConfigForSourcetype: function (sourcetype) {
        var columns = [];

        var me = this;
        switch (sourcetype) {
            case 'xml':
                columns.push({
                    header: t('pim.dataport-fields-xpath'),
                    dataIndex: 'xpath',
                    renderer: function (value) {
                        return Ext.util.Format.htmlEncode(value);
                    },
                    editor: new Ext.form.TextField({
                        allowBlank: false,
                        handleMouseEvents: true,
                        listeners: {
                            render: function (cmp) {
                                Ext.get(cmp.getInputId()).on('mousedown', function (e) {
                                    e.stopPropagation();
                                });
                            }
                        }
                    }),
                    flex: 2
                });
                break;
            case 'csv':
                columns.push({
                    header: t('pim.dataport.csv.fields.name'),
                    dataIndex: 'column',
                    renderer: function (value) {
                        return Ext.util.Format.htmlEncode(value);
                    },
                    editor: new Ext.form.TextField({
                        allowBlank: false,
                        handleMouseEvents: true,
                        listeners: {
                            render: function (cmp) {
                                Ext.get(cmp.getInputId()).on('mousedown', function (e) {
                                    e.stopPropagation();
                                });
                            }
                        }
                    }),
                    flex: 2
                });
                break;
            case 'excel':
                columns.push({
                    header: t('pim.dataport.excel.fields.name'),
                    dataIndex: 'column',
                    renderer: function (value) {
                        return Ext.util.Format.htmlEncode(value);
                    },
                    editor: new Ext.form.TextField({
                        allowBlank: false,
                        handleMouseEvents: true,
                        listeners: {
                            render: function (cmp) {
                                Ext.get(cmp.getInputId()).on('mousedown', function (e) {
                                    e.stopPropagation();
                                });
                            }
                        }
                    }),
                    flex: 2
                });
                break;
            case 'json':
                columns.push({
                    header: t('pim.dataport-fields-json-path'),
                    dataIndex: 'json-path',
                    renderer: function (value) {
                        return Ext.util.Format.htmlEncode(value);
                    },
                    editor: new Ext.form.TextField({
                        allowBlank: false,
                        handleMouseEvents: true,
                        listeners: {
                            render: function (cmp) {
                                Ext.get(cmp.getInputId()).on('mousedown', function (e) {
                                    e.stopPropagation();
                                });
                            }
                        }
                    }),
                    flex: 2
                });
                break;
            case 'pimcore':
                var pimcoreParameterStore = Ext.create('Ext.data.JsonStore', {
                    fields: ['item'],
                    autoload: true,
                    proxy: {
                        type: 'ajax',
                        url: '/admin/SylphenDataBridge/importconfig/get-data-query-selector-items',
                        reader: {
                            type: 'json',
                            rootProperty: 'items'
                        }
                    },
                    listeners: {
                        beforeload: function(store, operation) {
                            var el = pimcoreParameterField.inputEl.dom;
                            var rng, cursorPosition=-1;
                            if (typeof el.selectionStart=="number") {
                                cursorPosition=el.selectionEnd;
                            } else if (document.selection && el.createTextRange){
                                rng=document.selection.createRange();
                                rng.collapse(true);
                                rng.moveStart("character", -el.value.length);
                                cursorPosition=rng.text.length;
                            }

                            var sourceClass = me.sourceConfigBasePanel.getForm().findField('sourceClass').getValue();

                            operation.setParams({
                                value: pimcoreParameterField.getValue(),
                                cursorPosition: cursorPosition,
                                sourceClass: sourceClass
                            });

                            return true;
                        }
                    }
                });
                var pimcoreParameterField = new Ext.form.field.ComboBox({
                    allowBlank: false,
                    hideTrigger: true,
                    typeAhead: true,
                    minChars: 0,
                    tpl: Ext.create('Ext.XTemplate',
                      '<tpl for=".">',
                      '<li class="x-boundlist-item">{label}',
                      '</li>',
                      '</tpl>'
                    ),
                    displayField: 'value',
                    valueField: 'value',
                    store: pimcoreParameterStore,
                    triggerAction: 'all',
                    listeners: {
                        select: function (combo, record) {
                            if([':','('].indexOf(combo.value[combo.value.length - 1]) > -1) {
                                pimcoreParameterStore.on('load', function () {
                                    pimcoreParameterField.expand();
                                }, {
                                    single: true
                                });
                                pimcoreParameterStore.load();
                            }
                        },
                        render: function (cmp) {
                            Ext.get(cmp.getInputId()).on('mousedown', function (e) {
                                e.stopPropagation();
                            });
                        },
                        blur: function () {
                            pimcoreParameterField.collapse();
                        }
                    }
                });

                columns.push({
                    header: t('pim.dataport.opendxp.fields.data_query_selector'),
                    dataIndex: 'parameters',
                    editor: pimcoreParameterField,
                    flex: 2,
                    renderer: function(value) {
                        return Ext.util.Format.htmlEncode(value);
                    }
                }, {
                    header: t('pim.mapping.key_attribute'),
                    dataIndex: 'exportKey',
                    xtype: 'checkcolumn',
                    hidden: true
                });
                break;
            case 'report':
                columns.push({
                    header: t('pim.dataport.csv.fields.name'),
                    dataIndex: 'column',
                    renderer: function (value) {
                        return Ext.util.Format.htmlEncode(value);
                    },
                    editor: new Ext.form.TextField({
                        allowBlank: false,
                        handleMouseEvents: true,
                        listeners: {
                            render: function (cmp) {
                                Ext.get(cmp.getInputId()).on('mousedown', function (e) {
                                    e.stopPropagation();
                                });
                            }
                        }
                    }),
                    flex: 2
                });
                break;
            case 'grid':
                break;
            case 'files':
                columns.push({
                    header: t('pim.dataport.files.fields.command'),
                    dataIndex: 'cmd',
                    renderer: function (value) {
                        return Ext.util.Format.htmlEncode(value);
                    },
                    editor: new Ext.form.TextField({
                        allowBlank: true,
                        handleMouseEvents: true,
                        listeners: {
                            render: function (cmp) {
                                Ext.get(cmp.getInputId()).on('mousedown', function (e) {
                                    e.stopPropagation();
                                });
                            }
                        }
                    }),
                    flex: 2
                });
                break;
            case 'fixed-length':
                columns.push({
                    header: t('pim.dataport.fixed_length_files.fields.length'),
                    dataIndex: 'length',
                    renderer: function (value) {
                        return Ext.util.Format.htmlEncode(value);
                    },
                    editor: new Ext.form.TextField({
                        allowBlank: true,
                        handleMouseEvents: true
                    }),
                    flex: 2
                });
                break;
            case 'object-wizard':
                var allowedTypes = Object.keys(opendxp.object.classes.data);

                var groupedTypes = {};
                var groupNames = ["text", "numeric", "date", "select", "media", "relation", "geo", "crm", "structured", "other"];
                Ext.each(allowedTypes, function(type) {
                    if(['data','block','localizedfields','encryptedField','newsletterActive','newsletterConfirmed'].indexOf(type) > -1 || typeof opendxp.object.classes.data[type] !== 'function') {
                        return true;
                    }
                    var component = opendxp.object.classes.data[type];
                    var group = component.prototype.getGroup();
                    if(!groupedTypes[group]) {
                        groupedTypes[group] = [];
                        if (!in_array(group, groupNames)) {
                            groupNames.push(group);
                        }
                    }
                    groupedTypes[group].push({
                        type: type,
                        name: (component.prototype.getTypeName() ? component.prototype.getTypeName() : t(component.prototype.getType())),
                        iconCls: component.prototype.getIconClass()+' field-type-combo',
                    });
                });

                var renamedFieldTypes = {
                    'objectsMetadata': 'advancedManyToManyObjectRelation',
                    'href': 'manyToOneRelation',
                    'multihrefMetadata': 'advancedManyToManyRelation',
                    'objects': 'manyToManyObjectRelation',
                    'nonownerobjects': 'reverseObjectRelation',
                    'reverseManyToManyObjectRelation': 'reverseObjectRelation',
                    'multihref': 'manyToManyRelation'
                };

                for(var oldFieldType in renamedFieldTypes) {
                    var newFieldTypeExists = false;
                    Ext.each(groupNames, function (group) {
                        Ext.each(groupedTypes[group], function (type) {
                            if(type.type === renamedFieldTypes[oldFieldType]) {
                                newFieldTypeExists = true;
                                return false;
                            }
                        });

                        if(newFieldTypeExists) {
                            return false;
                        }
                    });

                    if(newFieldTypeExists) {
                        Ext.each(groupNames, function (group) {
                            var foundInGroup = false;
                            Ext.each(groupedTypes[group], function (type, index) {
                                if (type.type === oldFieldType) {
                                    groupedTypes[group].splice(index, 1);
                                    foundInGroup = true;
                                    return false;
                                }
                            });

                            if(foundInGroup) {
                                return false;
                            }
                        });
                    }
                }

                var storeData = [];
                Ext.each(groupNames, function (group) {
                    Ext.each(groupedTypes[group], function(type) {
                        storeData.push({
                            type: type.type,
                            name: type.name,
                            iconCls: type.iconCls,
                            group: t(group),
                            groupIconCls: 'opendxp_icon_data_group_'+group
                        });
                    });
                });

                columns.push({
                    header: t('pim.dataport.object_wizard.fields.field-type'),
                    dataIndex: 'type',
                    renderer: function (type) {
                        if(type) {
                            var component = opendxp.object.classes.data[type];
                            return (component.prototype.getTypeName() ? component.prototype.getTypeName() : t(component.prototype.getType()));
                        }
                    },
                    editor: new Ext.form.field.ComboBox({
                        typeAhead: true,
                        minChars: 0,
                        anyMatch: true,
                        displayField: 'name',
                        valueField: 'type',
                        queryMode: 'local',
                        store: Ext.create('Ext.data.Store', {
                            fields: ['name', 'type', 'group'],
                            data: storeData
                        }),
                        listConfig: {
                            tpl: [
                                '<tpl for=".">',
                                '{[xindex === 1 || parent[xindex - 2].group !== values.group ? "<div style=\'padding: 5px 0 5px 30px;background-position-x: left !important;\' class=\'"+values.groupIconCls+"\'>"+values.group+"</div>" : ""]}',
                                '<div role="option" class="x-boundlist-item {iconCls}" style="background-position-x: 10px !important;padding-left: 45px;">{name}</div>',
                                '</tpl>'
                            ]
                        },
                        triggerAction: 'all'
                    }),
                    flex: 2
                });

                columns.push({
                    xtype: 'actioncolumn',
                    menuText: t("classificationstore_detailed_configuration"),
                    width: 60,
                    items: [
                        {
                            tooltip: t("classificationstore_detailed_configuration"),
                            icon: "/bundles/opendxpadmin/img/flat-color-icons/department.svg",
                            handler: function (grid, rowIndex) {
                                var record = grid.getStore().getAt(rowIndex);
                                var type = record.get('type');
                                var definition = record.get('definition');
                                if(!definition) {
                                    definition = {
                                        name: record.get('name'),
                                        fieldtype: record.get('type'),
                                        datatype: 'data'
                                    };
                                } else {
                                    definition.name = record.get('name');
                                }

                                var editor = new opendxp.object.classes.data[record.get('type')](null, definition);
                                editor.setInClassificationStoreEditor(true);
                                var layout = editor.getLayout();

                                if(typeof editor.specificPanel !== 'undefined') {
                                    Ext.Array.each(editor.specificPanel.items.items, function (fieldDefinitionField) {
                                        if(fieldDefinitionField.name === 'allowToCreateNewObject') {
                                            fieldDefinitionField.hide();
                                        } else if (fieldDefinitionField.name === 'enableTextSelection') {
                                            fieldDefinitionField.hide();
                                            fieldDefinitionField.setValue(true);
                                        } else if (fieldDefinitionField.name === 'pathFormatterClass') {
                                            fieldDefinitionField.setValue('@DataBridgeSearchViewPathFormatter');
                                        }
                                    });
                                }

                                var invisibleFields = ["name","title","unique", "visibleGridView", "visibleSearch", "index"];
                                var invisibleField;
                                for (var f = 0; f < invisibleFields.length; f++) {
                                    invisibleField = layout.getComponent("standardSettings").getComponent(invisibleFields[f]);
                                    if (invisibleField) {
                                        invisibleField.hide();
                                    }
                                }

                                var window = new Ext.Window({
                                    modal: true,
                                    width: 800,
                                    height: 600,
                                    resizable: true,
                                    scrollable: "y",
                                    title: t('field') + ' ' + record.get('name'),
                                    items: [layout],
                                    bbar: [
                                        "->", {
                                            xtype: "button",
                                            text: t("cancel"),
                                            iconCls: "opendxp_icon_cancel",
                                            handler: function () {
                                                window.close();
                                            }.bind(this)
                                        }, {
                                            xtype: "button",
                                            text: t("apply"),
                                            iconCls: "opendxp_icon_apply",
                                            handler: function () {
                                                editor.applyData();
                                                var definition = editor.getData();
                                                definition.name = record.get('name');
                                                if (typeof definition.classes !== "undefined") {
                                                    definition.classes = Ext.Array.map(definition.classes, function (allowedClass) {
                                                        return { 'classes': allowedClass };
                                                    });
                                                }
                                                if (typeof definition.assetTypes !== "undefined") {
                                                    definition.assetTypes = Ext.Array.map(definition.assetTypes, function (assetType) {
                                                        return { 'assetTypes': assetType };
                                                    });
                                                }
                                                if (typeof definition.documentTypes !== "undefined") {
                                                    definition.assetTypes = Ext.Array.map(definition.documentTypes, function (documentType) {
                                                        return { 'documentTypes': documentType };
                                                    });
                                                }
                                                record.set('definition', definition);
                                                var type = record.get('type');
                                                record.set('type', null, {commit: true});
                                                record.set('type', type);
                                                window.close();
                                            }.bind(this)
                                        }],
                                    plain: true
                                });
                                window.show();
                            }
                        }
                    ]
                });
                break;
        }

        return columns;
    },

    save: function (cb) {
        if (!Ext.isFunction(cb)) {
            cb = function () {};
        }

        if (this.dataportPanel.getForm().isValid() && this.sourceConfigBasePanel.getForm().isValid()) {
            this.getEl().mask();
            var baseData = this.dataportPanel.getForm().getFieldValues();

            baseData = Object.assign(baseData, this.sourceConfigBasePanel.getForm().getFieldValues());
            delete baseData.fileEditor;

            // somehow inputValue: 1 does not work for the checkbox, so we convert boolean value here
            baseData.optimizeInheritance = baseData.optimizeInheritance ? 1 : 0;
            baseData.skipVersioning = baseData.skipVersioning ? 1 : 0;
            baseData.compatibilityMode = baseData.compatibilityMode ? 1 : 0;
            baseData.inheritanceEnabled = baseData.inheritanceEnabled ? 1 : 0;
            baseData.draftVersions = baseData.draftVersions ? 1 : 0;

            var gridData = [];
            if (this.sourceConfigRawitemPanel instanceof Ext.grid.Panel) {
                var store = this.sourceConfigRawitemPanel.getStore();
                var records = store.queryBy(function() { return true; }).getRange();
                Ext.each(records, function (record) {
                    var data = {};
                    var f = record.fields;

                    for (var i = 0, len = f.length; i < len; i++) {
                        var field = f[i];
                        data[field.name] = record.get(field.name);
                    }
                    gridData.push(data);
                });
            }

            var rawitemData = Ext.util.JSON.encode(gridData);

            baseData['id'] = this.dataport.id;
            baseData['rawitemData'] = rawitemData;
            baseData['lastModified'] = this.dataport.modificationDate;

            Ext.Ajax.request({
                url: "/admin/SylphenDataBridge/importconfig/update",
                method: 'post',
                params: baseData,
                success: function (response) {
                    response = Ext.decode(response.responseText);

                    if (!(response && response.success)) {
                        if(typeof response.lastModified !== "undefined" && response.lastModified) {
                            var messageBox = new Ext.window.MessageBox();
                            messageBox.show({
                                title: t('pim.conflict.title'),
                                msg: t('pim.conflict').replace('%s', response.lastModifiedUser),
                                buttons: Ext.Msg.OK & Ext.Msg.YES & Ext.Msg.NO,
                                buttonText: { ok: t('pim.conflict.force'), yes: t('pim.conflict.check_versions'), no: t('pim.conflict.cancel') },
                                prompt: false,
                                icon: Ext.MessageBox.QUESTION,
                                fn: function (action) {
                                    if (action === 'ok') {
                                        this.dataport.modificationDate = response.lastModified;
                                        this.save(cb);
                                    } else if (action === 'yes') {
                                        this.configPanel.queryById('versionButton').fireHandler();
                                        this.getEl().unmask();
                                    } else if (action === 'no' || action === 'cancel') {
                                        this.getEl().unmask();
                                    }
                                }.bind(this)
                            });
                        } else {
                            this.getEl().unmask();
                            opendxp.helpers.showNotification(t("error"), response.errorMessage, "error");

                            cb(new Error(response.errorMessage));
                        }
                    } else {
                        if(this.dataport.name !== baseData.name) {
                            this.importConfigPanel.reloadTree();
                        }
                        this.populateDataportForm();
                        this.previewPanel.rebuild();

                        this.mappingPanel.getStore().load();

                        if(this.manualPanel.sourceType === 'object-wizard') {
                            this.manualPanel.getStartWindow().close();
                            this.manualPanel.formWindow = null;
                        }
                        cb();
                    }
                }.bind(this),
                failure: function (response) {
                    this.getEl().unmask();
                    response = Ext.decode(response.responseText);

                    opendxp.helpers.showNotification(t("error"), response.errorMessage, "error");
                    cb(new Error(response.errorMessage));
                }.bind(this)
            });
        }
    }
});