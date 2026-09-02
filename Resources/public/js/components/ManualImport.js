/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


opendxp.registerNS("opendxp.plugin.Pim.ImportConfig");
opendxp.plugin.Pim.ManualImport = Ext.extend(Ext.Panel, {
    dataportId: null,
    sourceType: null,
    importForm: null,
    formWindow: null,
    restApiWindow: null,
    statusGrid: null,
    isExport: false,
    settingsPanel: null,
    timeout: null,
    queueProcessingError: null,
    queueItemExists: false,
    objectWizardEdit: null,

    initComponent: function () {
        /**
         * @see https://forum.sencha.com/forum/showthread.php?470126-TagField-autoSelect-and-createNewOnEnter-issue
         */
        if (typeof Overridden === 'undefined') {
            Ext.define('Overridden.form.field.Tag', {
                extend: 'Ext.form.field.Tag',
                alias: 'widget.new-tagfield',

                createNewOnEnter: true,
                createNewOnBlur: true,
                filterPickList: true,
                minChars: 1,
                forceSelection: false,
                queryMode: 'remote',
                triggerOnClick: false,
                autoSelect: false,
                selectOnTab: false,

                // filtro itens na lista de opções
                // necessita @filterPickList: true
                filterPicked: function (rec) {
                    const me = this;
                    let keepIt = true;

                    this.valueCollection.each(function (item) {
                        if (item.get(me.valueField) === rec.get(me.valueField)) {
                            keepIt = false;
                            return false;
                        }
                    });

                    return keepIt;
                },

                // se autoSelect e forceSelecion forem false reseta seleção
                // necessita @autoSelect e forceSelecion: false
                doAutoSelect: function () {
                    const me = this;

                    if (!me.autoSelect && !me.forceSelection) {
                        me.picker.getNavigationModel().setPosition(null);
                    }

                    me.callParent();
                },

                // sempre reseta valor do input text
                clearInput: function () {
                    const me = this,
                        inputValue = me.inputEl && me.inputEl.dom.value;

                    me.callParent();

                    if (inputValue) {
                        me.inputEl.dom.value = '';
                    }
                },

                // ao selecionar um registro com o enter com autoSelect false remove próxima selação
                // necessita @autoSelect: false, createNewOnEnter: true
                onKeyUp: function (e) {
                    const me = this;

                    me.callParent(arguments);

                    if (!me.autoSelect && me.createNewOnEnter && e.getKey() === e.ENTER) {
                        me.picker.getNavigationModel().setPosition(null);
                        me.picker.setHighlightedItem(null);
                    }
                }
            });
        }

        Ext.apply(this, {
            left: 0,
            padding: 10,
            xtype: 'panel',
            dockedItems: [{
                xtype: 'toolbar',
                itemId: 'toolbar',
                dock: 'top',
                items: [
                    Ext.create('Ext.button.Split', {
                        itemId: 'start_complete_button',
                        text: t('pim.manual.startimport.complete'),
                        icon: '/bundles/opendxpadmin/img/flat-color-icons/go.svg',
                        handler: function (b, e) {
                            this.getStartWindow().getComponent('startForm').getForm().findField('importType').setValue('complete');
                            if (this.isExport) {
                                this.getStartWindow().getComponent('startForm').getForm().findField('dry-run').setValue(false).hide();
                            } else {
                                this.getStartWindow().getComponent('startForm').getForm().findField('dry-run').show();
                            }

                            this.getStartWindow().setTitle(this.isExport ? t('pim.manual.startimport.complete.export') : t('pim.manual.startimport.complete'));
                            this.getStartWindow().show();
                            Ext.each(this.getStartWindow().getComponent('startForm').getForm().getFields().items, function (field) {
                                if (field.isVisible()) {
                                    field.focus();
                                    return false;
                                }
                            });
                        }.bind(this),
                        menu: new Ext.menu.Menu({
                            items: [
                                {
                                    itemId: 'start_rawdata_button',
                                    text: t('pim.manual.startimport.rawdata'),
                                    icon: '/bundles/opendxpadmin/img/flat-color-icons/feed_in.svg',
                                    handler: function (b, e) {
                                        this.getStartWindow().getComponent('startForm').getForm().findField('importType').setValue('raw');
                                        this.getStartWindow().getComponent('startForm').getForm().findField('dry-run').setValue(false).hide();
                                        this.getStartWindow().setTitle(this.isExport ? t('pim.manual.startimport.rawdata.export') : t('pim.manual.startimport.rawdata'));
                                        this.getStartWindow().show();

                                        Ext.each(this.getStartWindow().getComponent('startForm').getForm().getFields().items, function (field) {
                                            if (field.isVisible()) {
                                                field.focus();
                                                return false;
                                            }
                                        });
                                    }.bind(this)
                                }, {
                                    itemId: 'start_rawdata_processing_button',
                                    text: t('pim.manual.startimport.pim'),
                                    iconCls: 'opendxp_icon_manyToManyObjectRelation',
                                    handler: function (b, e) {
                                        Ext.Msg.show({
                                            title: t('pim.manual.startimport.pim.title'),
                                            msg: t('pim.manual.startimport.pim.text') + (this.isExport ? '<br/><br/><label><input type="checkbox" id="force" /> ' + t('pim.manual.startimport.pim.force.export') + '</label>' : '<br/><br/><label><input type="checkbox" id="force" checked /> ' + t('pim.manual.startimport.pim.force') + '</label>'),
                                            buttons: Ext.Msg.YESNO,
                                            fn: function (btn) {
                                                if (btn == 'yes') {
                                                    // Basic request
                                                    Ext.Ajax.request({
                                                        url: '/admin/SylphenDataBridge/import/manual-pim-import',
                                                        params: {
                                                            dataportId: this.dataportId,
                                                            force: document.getElementById('force').checked ? 0 : 1 // look at translation -> logic is !force
                                                        },
                                                        success: function (response) {
                                                            try {
                                                                response = Ext.decode(response.responseText);
                                                                if (!(response && response.success)) {
                                                                    opendxp.helpers.showNotification(t("error"), t("pim.error_creating_dataport"), "error", t(response.msg));
                                                                } else {
                                                                    if (response.url) {
                                                                        var popup = window.open(response.url);
                                                                        if (!popup || popup.closed || typeof popup.closed == 'undefined') {
                                                                            Ext.MessageBox.alert(t('error'), response.msg);
                                                                        }
                                                                    } else if (typeof response.statusKey !== "undefined") {
                                                                        this.showSummaryWindow(response.statusKey);
                                                                    } else {
                                                                        opendxp.helpers.showNotification(t("success"), t('pim.manual.importForm.success'), 'success');
                                                                    }
                                                                }
                                                            } catch (e) {
                                                                opendxp.helpers.showNotification(t("error"), t("pim.manual.importForm.failure"), "error", e.message);
                                                            }
                                                        }.bind(this),
                                                        failure: function (response) {
                                                            var msg = '';
                                                            if (response.msg) {
                                                                msg = '<br>' + t(response.msg);
                                                            }

                                                            Ext.MessageBox.alert(t('error'), t('pim.manual.importForm.failure') + msg);
                                                        }.bind(this)
                                                    });
                                                }
                                            }.bind(this),
                                            icon: Ext.MessageBox.QUESTION
                                        });
                                    }.bind(this)
                                }
                            ]
                        })
                    }),
                    '->',
                    {
                        text: t('pim.manual.start_queue_processor'),
                        itemId: 'queue_processing_error_button',
                        iconCls: 'queue-processing-monitor-icon',
                        tooltip: t('pim.manual.start_queue_processor.tooltip'),
                        handler: function (button) {
                            opendxp.helpers.openGenericIframeWindow("data-bridge-queue-monitor", "/admin/SylphenDataBridge/import/start-queue-processing", "queue-processing-monitor-icon", "Data Bridge Queue Monitor");
                        }.bind(this)
                    },
                    {
                        text: t('pim.rest_api.documentation'),
                        iconCls: 'opendxp_icon_api_documentation',
                        handler: function (b, e) {
                            if(this.restApiWindow === null) {
                                this.restApiWindow = new Ext.Window({
                                    title: t('pim.rest_api.documentation') + ' (' + t('pim.manual.statusgrid.dataport') + ' "' + this.settingsPanel.getForm().findField('name').getValue() + '")',
                                    width: '80%',
                                    height: '80%',
                                    layout: 'fit',
                                    closeAction: 'hide',
                                    items: [
                                        {
                                            xtype: "component",
                                            autoEl: {
                                                tag: "iframe",
                                                src: "/admin/SylphenDataBridge/rest/documentation/" + this.dataportId
                                            },
                                            border: false
                                        }
                                    ]
                                });
                            }
                            this.restApiWindow.show();
                        }.bind(this)
                    }
                ]
            },
            {
                xtype: 'toolbar',
                dock: 'top',
                layout: 'fit',
                items: [
                    new Overridden.form.field.Tag({
                        fieldLabel: t('filter'),
                        store: Ext.create('Ext.data.Store', {
                            fields: ['type', 'label'],
                            data: [
                                { type: 'successful', label: t('pim.manual.filter.successful'), customizable: false },
                                { type: 'errors', label: t('pim.manual.filter.errors'), customizable: false },
                                { type: 'aborted', label: t('pim.manual.filter.aborted'), customizable: false },
                                { type: 'running', label: t('pim.manual.filter.running'), customizable: false },
                                { type: 'queued', label: t('pim.manual.filter.queued'), customizable: false },
                                { type: 'dry-run', label: t('pim.manual.startimport.dry-run.importType'), customizable: false },
                                { type: 'done > 1', label: t('pim.manual.filter.done'), customizable: true },
                                { type: 'total > 1', label: t('pim.manual.filter.total'), customizable: true },
                            ]
                        }),
                        displayField: 'label',
                        valueField: 'type',
                        filterPickList: true,
                        forceSelection: false,
                        createNewOnEnter: true,
                        autoSelect: false,
                        labelWidth: 50,
                        queryMode: 'local',
                        anyMatch: true,
                        labelTpl: '<tpl if="type!==\'successful\' && type!==\'errors\' && type!==\'queued\' && type!==\'running\' && type!==\'aborted\' && type!==\'dry-run\'">'+t('search')+': </tpl><tpl if="customizable">{type}<tpl else>{label}</tpl>',
                        listeners: {
                            change: function(field, value) {
                                this.statusGrid.setLoading(true);
                                this.statusGrid.getStore().getProxy().setExtraParam("search[]", field.getValue());
                                this.statusGrid.getStore().load();
                                this.statusGrid.setLoading(false);

                                this.statusGrid.getDockedItems('toolbar')[0].moveFirst();
                            }.bind(this),
                            focus: function (combo) {
                                setTimeout(function () {
                                    if (!combo.isExpanded) {
                                        combo.expand();
                                    }
                                }, 100);
                            }
                        }
                    })
                ]
            }]
        });

        opendxp.plugin.Pim.ManualImport.superclass.initComponent.call(this);

        // show the panels by refreshing
        opendxp.layout.refresh();
    },

    getStartWindow: function(parameters) {
        if(this.formWindow === null) {
            var items = [];
            if (this.sourceType === 'pimcore') {
                items.push({
                    xtype: 'textfield',
                    emptyText: 'SQL',
                    fieldLabel: t('filter_condition'),
                    name: 'importfile',
                    listeners: {
                        specialkey: function (combo, e) {
                            if (e.getKey() == e.ENTER) {
                                this.getStartWindow().queryById("btnSave").fireHandler();
                            }
                        }.bind(this)
                    }
                });

                var localeData = [];
                var currentLanguage = null;
                for (var i = 0; i < opendxp.settings.websiteLanguages.length; i++) {
                    localeData.push([opendxp.settings.websiteLanguages[i], t(opendxp.available_languages[opendxp.settings.websiteLanguages[i]])]);

                    if(opendxp.globalmanager.get("user").language === opendxp.settings.websiteLanguages[i]) {
                        currentLanguage = opendxp.settings.websiteLanguages[i];
                    }
                }

                if(currentLanguage === null) {
                    for (var i = 0; i < opendxp.settings.websiteLanguages.length; i++) {
                        if (opendxp.settings.websiteLanguages[i].indexOf(opendxp.globalmanager.get("user").language + '_') === 0) {
                            currentLanguage = opendxp.settings.websiteLanguages[i];
                            break;
                        }
                    }
                }

                if(currentLanguage === null) {
                    Ext.Ajax.request({
                        url: Routing.generate('opendxp_admin_settings_getsystem'),
                        async: false,
                        success: function (response) {
                            var responseData = Ext.decode(response.responseText);
                            currentLanguage = responseData.values.general.default_language
                        }.bind(this)
                    });
                }

                items.push({
                    xtype: 'combo',
                    name: 'locale',
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
                    value: currentLanguage,
                    listConfig: {
                        tpl: [
                            '<tpl for=".">',
                            '<div role="option" class="x-boundlist-item"><div style="height:21px;display:inline-block;padding-left:34px;background-position: 0 0" class="opendxp_icon_language_{[values.key.toLowerCase()]}">{value}</div></div>',
                            '</tpl>'
                        ]
                    },
                    listeners: {
                        focus: function (combo) {
                            setTimeout(function () {
                                if (!combo.isExpanded) {
                                    combo.expand();
                                }
                            }, 100);
                        }
                    }
                });
            } else if(['csv','json','xml','excel','fixed-length'].indexOf(this.sourceType) > -1) {
                items.push({
                    xtype: 'fileuploadfield',
                    emptyText: t('pim.manual.importForm.emptyUpload'),
                    fieldLabel: t('pim.manual.importForm.uploadLabel'),
                    name: 'importfile',
                    buttonText: t('select_a_file'),
                    buttonCfg: {
                        iconCls: 'opendxp_icon_file'
                    }
                });
            } else if(this.sourceType === 'object-wizard') {
                Ext.Ajax.request({
                    url: '/admin/SylphenDataBridge/import/object-wizard-layout/' + this.dataportId,
                    success: function (response) {
                        try {
                            response = Ext.decode(response.responseText);
                            if (!(response && response.success)) {
                                opendxp.helpers.showNotification(t("error"), 'Could not load object wizard form', "error", e.message);
                            } else {
                                var fieldDefinitions = response.fields;
                                var fields = [];
                                Ext.Object.each(fieldDefinitions, function(fieldNo, rawItemField) {
                                    rawItemField.definition.title = rawItemField.definition.name;
                                    fields.push(rawItemField.definition);
                                });

                                this.objectWizardEdit = new opendxp.object.edit({ id: response.objectId, data: { data: parameters || {}, metaData: {}, general: { allowInheritance: false } } });

                                var dataFields = this.objectWizardEdit.getRecursiveLayout({
                                    title: '',
                                    datatype: 'layout',
                                    fieldtype: 'panel',
                                    children: fields,
                                    childs: fields,
                                    labelWidth: 200
                                }, false, {applyDefaults: true}).items;

                                if (dataFields && dataFields.length > 0) {
                                    this.getStartWindow().getComponent('startForm').insert(0, dataFields);
                                    this.formWindow.setSize('80%', '80%').center();
                                } else {
                                    var startButton = this.getStartWindow().queryById("btnSave");
                                    Ext.callback(startButton.handler, startButton.scope, [startButton], 0, startButton);
                                }
                            }
                        } catch (e) {
                            opendxp.helpers.showNotification(t("error"), 'Could not load layout', "error", e.message);
                        }
                    }.bind(this)
                });
            }

            Ext.Ajax.request({
                url: '/admin/SylphenDataBridge/import/get-parameter-fields/'+this.dataportId,
                success: function (response) {
                    try {
                        response = Ext.decode(response.responseText);
                        if (response && response.success) {
                            var dryRunCheckboxIndex = 0;
                            startForm.items.each(function(formField, formFieldIndex) {
                                if(typeof formField.getName === 'function' && formField.getName() === 'dry-run') {
                                    dryRunCheckboxIndex = formFieldIndex;
                                    return false;
                                }
                            });
                            
                            Ext.each(response.parameterFields.reverse(), function(parameterField) {
                                startForm.insert(dryRunCheckboxIndex, {
                                    xtype: 'textfield',
                                    fieldLabel: parameterField,
                                    name: parameterField,
                                    listeners: {
                                        specialkey: function (combo, e) {
                                            if (e.getKey() == e.ENTER) {
                                                this.getStartWindow().queryById("btnSave").fireHandler();
                                            }
                                        }.bind(this)
                                    }
                                });
                            });
                        }
                    } catch (e) {
                        opendxp.helpers.showNotification(t("error"), t("pim.manual.importForm.failure"), "error", e.message);
                    }
                }.bind(this)
            });

            items.push(
                {
                    xtype: 'checkbox',
                    name: 'dry-run',
                    boxLabel: t('pim.manual.startimport.dry-run'),
                    inputValue: 1,
                    hidden: this.isExport
                },
                {
                    xtype: 'hidden',
                    name: 'dataportId',
                    value: this.dataportId
                }, {
                    xtype: 'hidden',
                    name: 'importType',
                    listeners: {
                        change: function(field, value) {
                            if(value === 'raw') {
                                value = 'rawdata';
                            }
                            var startButton = this.getStartWindow().queryById("btnSave");
                            startButton.setText(this.getButtonTitle(value));
                            if(value === 'rawdata') {
                                startButton.setIcon("/bundles/opendxpadmin/img/flat-color-icons/feed_in.svg");
                            } else {
                                startButton.setIcon(this.isExport ? "/bundles/opendxpadmin/img/flat-color-icons/export.svg" : "/bundles/opendxpadmin/img/flat-color-icons/feed_in.svg");
                            }
                        }.bind(this)
                    }
                }, {
                    xtype: 'hidden',
                    name: 'force',
                    value: 1
                }, {
                    xtype: 'hidden',
                    name: 'csrfToken',
                    value: opendxp.settings['csrfToken'],
                }
            );

            var startForm = Ext.create('Ext.form.Panel', {
                itemId: 'startForm',
                padding: 15,
                layout: {
                    type: 'vbox',
                    align: 'stretch'
                },
                items: items
            });
            this.formWindow = Ext.create('Ext.Window', {
                layout: {
                    type: 'vbox',
                    align: 'stretch'
                },
                title: t('pim.manual.importForm.title'),
                width: 500,
                height: 300,
                maximizable: this.sourceType === 'object-wizard',
                closeAction: 'hide',
                items: startForm,
                scrollable: true,
                buttons: [{
                    text: t('pim.manual.importForm.start'),
                    icon: this.isExport ? "/bundles/opendxpadmin/img/flat-color-icons/export.svg" : "/bundles/opendxpadmin/img/flat-color-icons/feed_in.svg",
                    itemId: 'btnSave',
                    handler: function () {
                        if (startForm.getForm().isValid()) {
                            var additionalValues = startForm.getForm().getFieldValues();
                            if (this.objectWizardEdit !== null) {
                                for (var objectWizardFieldName in this.objectWizardEdit.dataFields) {
                                    var value = this.objectWizardEdit.dataFields[objectWizardFieldName].getValue();

                                    if(typeof this.objectWizardEdit.dataFields[objectWizardFieldName].component.validate === 'function' && !this.objectWizardEdit.dataFields[objectWizardFieldName].component.validate()) {
                                        return;
                                    }

                                    if(this.objectWizardEdit.dataFields[objectWizardFieldName].fieldConfig.mandatory && (!value || (Array.isArray(value) && value.length === 0))) {
                                        if(typeof this.objectWizardEdit.dataFields[objectWizardFieldName].component.markInvalid === 'function') {
                                            this.objectWizardEdit.dataFields[objectWizardFieldName].component.markInvalid(t('mandatoryfield'));
                                        } else {
                                            Ext.create('Ext.tip.ToolTip', {
                                                target: this.objectWizardEdit.dataFields[objectWizardFieldName].component.getEl(),
                                                html: t("mandatoryfield")
                                            }).showBy(this.objectWizardEdit.dataFields[objectWizardFieldName].component.getEl());
                                            this.objectWizardEdit.dataFields[objectWizardFieldName].component.addCls('mandatory_field_error');
                                        }

                                        return;
                                    } else {
                                        this.objectWizardEdit.dataFields[objectWizardFieldName].component.removeCls('mandatory_field_error');
                                    }

                                    if (typeof value === 'object') {
                                        if (Array.isArray(value)) {
                                            value = value.map(function (item) {
                                                delete item.inheritedFields;
                                                delete item.subtype;
                                                delete item.idPath;
                                                delete item.permissions;
                                                delete item.locked;
                                                delete item.rowId;
                                                return item;
                                            });
                                        }
                                        value = JSON.stringify(value);
                                    } else if(typeof value === 'boolean') {
                                        value = value ? 1 : 0;
                                    }

                                    additionalValues[objectWizardFieldName] = value;
                                }
                            }

                            additionalValues['dry-run'] = additionalValues['dry-run'] ? 1 : 0;

                            var requestParams = {
                                url: '/admin/SylphenDataBridge/import/manual-import',
                                method: 'post',
                                params: additionalValues,
                                waitMsg: t('pim.manual.importForm.waitMsg'),
                                success: function (form, response) {
                                    response = response.result ?? Ext.decode(form.responseText);
                                    if (!additionalValues['dry-run']) {
                                        this.formWindow.hide();
                                    }
                                    if(typeof response.statusKey !== "undefined") {
                                        this.showSummaryWindow(response.statusKey);
                                    }
                                }.bind(this),
                                failure: function (form, action) {
                                    if (!additionalValues['dry-run']) {
                                        this.formWindow.hide();
                                    }

                                    var msg = '';
                                    if (action && action.result.msg) {
                                        msg = '<br>' + action.result.msg;
                                    }

                                    Ext.MessageBox.alert(t('error'), t('pim.manual.importForm.failure') + msg);
                                }.bind(this)
                            };

                            if(['csv', 'json', 'xml', 'excel', 'fixed-length'].indexOf(this.sourceType) > -1) {
                                startForm.getForm().submit(requestParams);
                            } else {
                                Ext.Ajax.request(requestParams);
                            }

                        }
                    }.bind(this)
                }]
            });
        }
        return this.formWindow;
    },

    showSummaryWindow: function(statusKey) {
        var elementType = '';
        var resultDocumentUrl = '';
        var refreshTimeout;
        var store = Ext.create('Ext.data.JsonStore', {
            fields: ['lock', 'field', 'old', 'new', 'diff', 'fullpath', 'elementId', 'elementType', 'classId'],
            groupField: 'fullpath',
            proxy: {
                type: 'ajax',
                api: {
                    read: '/admin/SylphenDataBridge/import/get-changed-elements/' + statusKey
                        + '?dry-run=' + (this.getStartWindow().getComponent('startForm').getForm().findField('dry-run').getValue() ? 1 : 0)
                        + '&dataportId=' + this.dataportId
                    ,
                    update: '/admin/SylphenDataBridge/import/ignore'
                },
                reader: {
                    type: 'json',
                    rootProperty: 'changedElements',
                    totalProperty: 'totalElements',
                    messageProperty: 'message',
                    keepRawData: true
                },
                writer: {
                    type: 'json',
                    writeAllFields : true,
                    allowSingle :false
                },
            },
            remoteSort: true,
            listeners: {
                write: function (proxyStore, operation) {
                    var response = operation.getResponse();
                    var missing = response && response.responseJson && response.responseJson.missingObjectIds;
                    if (!missing || missing.length === 0) {
                        return;
                    }
                    missing.forEach(function (entry) {
                        proxyStore.each(function (rec) {
                            if (rec.get('fullpath') === entry.fullpath && rec.get('field') === entry.field && rec.get('lock')) {
                                rec.set('lock', false);
                                rec.commit();
                            }
                        });
                    });
                    var uniquePaths = Array.from(new Set(missing.map(function (e) { return e.fullpath; })));
                    var pathList = uniquePaths.map(function (p) { return Ext.htmlEncode(p); }).join('<br/>');
                    Ext.MessageBox.alert(
                        t('pim.manual.startimport.summary.dry-run.lock'),
                        Ext.String.format(t('pim.manual.startimport.summary.dry-run.lock-not-allowed.new-object'), missing.length, pathList)
                    );
                },
                load: function () {
                    var response = store.getProxy().getReader().rawData;

                    if (response.doneItems && response.totalItems) {
                        summaryWindow.queryById('progressBar').setHtml('<div style="background-color:#e0e0e0;padding:3px;border-radius:3px;box-shadow:inset 0 1px 3px rgba(0, 0, 0, .2);"><span class="progress-bar-fill" style="width:' + ((!response.totalItems) ? 0 : round(response.doneItems / response.totalItems * 100)) + '%;display:block;height:22px;background-color:#47ba60;border-radius:3px;transition:width 500ms ease-in-out;text-align:right;padding:2px 10px;white-space:nowrap">' + (response.finishedIn ? (response.comment ? response.comment + ': ' : '')+response.doneItems + ' / ' + response.totalItems + ', ' + t('pim.manual.startimport.summary.finished_in') + ' ' + response.finishedIn : '') + '</span></div>');
                    } else {
                        summaryWindow.queryById('progressBar').setHtml('<div style="background-color:#e0e0e0;padding:3px;border-radius:3px;box-shadow:inset 0 1px 3px rgba(0, 0, 0, .2);"><span class="progress-bar-fill" style="width:0;display:block;height:22px;background-color:#47ba60;border-radius:3px;transition:width 500ms ease-in-out;text-align:right;padding:2px 10px;white-space:nowrap">' + t('pim.manual.importForm.success') + ' ...</span></div>');
                    }

                    if (response.logFile) {
                        summaryWindow.queryById('logExplorer').setHref('/admin/SylphenDataBridge/import/log/' + response.logFile);
                    }

                    elementType = 'object';
                    var exportIframe = summaryWindow.queryById('exportIframe');
                    if(response.responseFile) {
                        if (location.protocol === 'https' && response.responseFile.indexOf('http://')) {
                            response.responseFile = response.responseFile.replace('http://', 'https://');
                        }


                        if(exportIframe.items.length === 0) {
                            exportIframe.add({
                                xtype: "component",
                                autoEl: {
                                    tag: "iframe",
                                    src: response.responseFile
                                },
                                border: false
                            });
                            }
                    }

                    if (!response.finished || (this.isExport && response.responseFile === null)) {
                        if(refreshTimeout) {
                            clearTimeout(refreshTimeout);
                        }
                        refreshTimeout = setTimeout(function () {
                            store.load();
                        }, 1000);
                    }

                    var cntChanges = response.changedElements.length;
                    if (response.changedElements.length > 0 && response.changedElements[0].elementId === "") {
                        cntChanges = 0;
                    }

                    if (cntChanges > 0 || response.responseFile === null) {
                        summaryWindow.queryById('importPanel').show();

                        if(summaryWindow.queryById('importPanel').features[0].isAllExpanded()) {
                            summaryWindow.queryById('importPanel').features[0].collapseAll();
                        }

                        if (summaryWindow.queryById('errorPanel').getCollapsed()) {
                            summaryWindow.queryById('importPanel').expand();
                        }
                    } else {
                        summaryWindow.queryById('importPanel').hide();

                        if (summaryWindow.queryById('errorPanel').getCollapsed() && exportIframe) {
                            exportIframe.expand();
                        }
                    }

                    var hasRealError = true;
                    if(response.errors.length > 0 && response.errors[0].type === "info") {
                        hasRealError = false;
                    }
                    summaryWindow.queryById('errorPanel').setTitle(t('pim.manual.startimport.summary.errors')+' (' + (hasRealError ? response.errors.length : 0) + ')');

                    errorStore.loadData(response.errors, false);
                }.bind(this)
            }
        });
        store.proxy.setTimeout(300000);
        store.load();

        var errorStore = Ext.create('Ext.data.Store', {
            fields: ['elementPath', 'field', 'message', 'type', 'elementType'],
            data: []
        });

        var toolbar = opendxp.helpers.grid.buildDefaultPagingToolbar(store, { pageSize: 25 });

        var panelItems = [
            Ext.create('Ext.grid.Panel', {
                itemId: 'errorPanel',
                collapsible: true,
                store: errorStore,
                collapsed: true,
                title: t('pim.manual.startimport.summary.errors'),
                scrollable: true,
                columns: [
                    {
                        text: t('element'),
                        dataIndex: 'elementPath',
                        flex: 1,
                        renderer: function (value, meta, record) {
                            if (typeof value === "undefined") {
                                return t('unknown');
                            }
                            return '<span style="cursor:pointer;text-decoration:underline">' + value + '</span>';
                        }
                    },
                    { text: t('field'), dataIndex: 'field', width: 100 },
                    { text: t('message'), dataIndex: 'message', flex: 1 },
                ],
                viewConfig: {
                    loadMask: false,
                    emptyText: t('loading')+' ...',
                    enableTextSelection: true,
                    getRowClass: function (record, rowIndex, rowParams, store) {
                        return (record.get('type')) ? 'log-' + record.get('type') : '';
                    }
                },
                listeners: {
                    cellclick: function (grid, tdElement, columnIndex, record) {
                        var dataIndex = grid.getHeaderCt().getHeaderAtIndex(columnIndex).dataIndex;
                        if (dataIndex === 'elementPath') {
                            opendxp.helpers.openElement(record.get('elementPath'), record.get('elementType'));
                        }
                    },
                    celldblclick: function (grid, cell, cellIndex, record) {
                        var dataIndex = grid.getHeaderCt().getHeaderAtIndex(cellIndex).dataIndex;
                        if (dataIndex === 'message') {
                            opendxp.helpers.copyStringToClipboard(record.get('message'));
                            opendxp.helpers.showNotification(t("info"), t('pim.mapping.copied_to_clipboard_mapping'), 'info');
                        }
                    }
                }
            }),
            Ext.create('Ext.grid.Panel', {
                itemId: 'importPanel',
                title: t('pim.manual.startimport.summary.changes'),
                collapsed: false,
                collapsible: true,
                store: store,
                scrollable: true,
                columns: [
                    { text: t('field'), dataIndex: 'fieldName', width: 100 },
                    { text: t('pim.manual.startimport.summary.old'), dataIndex: 'old', flex: 1 },
                    { text: t('pim.manual.startimport.summary.new'), dataIndex: 'new', flex: 1 },
                    { text: t('pim.manual.startimport.summary.diff'), dataIndex: 'diff', flex: 1 },
                    {
                        xtype: 'actioncolumn',
                        header: t('pim.manual.startimport.summary.dry-run.lock'),
                        dataIndex: 'lock',
                        items: [{
                            tooltip: t('pim.manual.startimport.summary.dry-run.lock.tooltip'),
                            getClass: function (v, metadata, record) {
                                if (record.get('new') === 'unchanged') {
                                    return '';
                                } else if (record.get('lock')) {
                                    return 'opendxp_icon_disapprove';
                                } else if (!record.get('lock')) {
                                    return 'opendxp_icon_approve';
                                }
                            },
                            handler: function (grid, rowIndex, colIndex, item, e, record) {
                                if (record.get('new') !== 'unchanged') {
                                    record.set('lock', !record.get('lock'));
                                    grid.store.sync();
                                }
                            }
                        }]
                    }
                ],
                features: [
                    Ext.create('Ext.grid.feature.Grouping', {
                        groupHeaderTpl: '<i>{name}</i>',
                        startCollapsed: true,
                        enableGroupingMenu: false,
                    })
                ],
                listeners: {
                    cellclick: function (grid, tdElement, columnIndex, record) {
                        if (columnIndex > 0) {
                            if (record.get('elementType')) {
                                opendxp.helpers.openElement(record.get('elementId'), record.get('elementType'));
                            } else if (elementType) {
                                opendxp.helpers.openElement(record.get('fullpath'), elementType);
                            }
                        }
                    },
                    itemcontextmenu: function(table, record, tr, rowIndex, e) {
                        let field = record.get('field');

                        if(record.get('new') !== 'unchanged') {
                            var menu = new Ext.menu.Menu();

                            if(record.get('lock')) {
                                menu.add({
                                    text: t('pim.manual.startimport.summary.dry-run.allow-field-for-all-objects'),
                                    icon: "/bundles/opendxpadmin/img/flat-color-icons/approve.svg",
                                    handler: function () {
                                        if (field) {
                                            function processAllPages (store, processFn, page = 1) {
                                                store.loadPage(page, {
                                                    callback: function (records, operation, success) {
                                                        if (success && records.length > 0) {
                                                            Ext.Array.each(records, processFn);
                                                            table.store.sync();

                                                            // If the number of records is less than the pageSize, it's the last page
                                                            if (records.length >= store.pageSize) {
                                                                processAllPages(store, processFn, page + 1);
                                                            }
                                                        }
                                                    }
                                                });
                                            }

                                            processAllPages(table.store, function (rec) {
                                                if (rec.get('field') === field) {
                                                    rec.set('lock', false);
                                                }
                                            });

                                            table.store.sync();
                                        }
                                    }
                                });

                                menu.add({
                                    text: t('pim.manual.startimport.summary.dry-run.allow-all-values-for-field'),
                                    icon: "/bundles/opendxpadmin/img/flat-color-icons/approve.svg",
                                    handler: function () {
                                        if (field) {
                                            function processAllPages (store, processFn, page = 1) {
                                                store.loadPage(page, {
                                                    callback: function (records, operation, success) {
                                                        if (success && records.length > 0) {
                                                            Ext.Array.each(records, processFn);
                                                            table.store.sync();

                                                            // If the number of records is less than the pageSize, it's the last page
                                                            if (records.length >= store.pageSize) {
                                                                processAllPages(store, processFn, page + 1);
                                                            }
                                                        }
                                                    }
                                                });
                                            }

                                            processAllPages(table.store, function (rec) {
                                                rec.set('lock', false);
                                            });

                                            table.store.sync();
                                        }
                                    }
                                });
                            } else {
                                menu.add({
                                    text: t('pim.manual.startimport.summary.dry-run.lock-field-for-all-objects'),
                                    icon: "/bundles/opendxpadmin/img/flat-color-icons/disapprove.svg",
                                    handler: function () {
                                        Ext.MessageBox.confirm(t('pim.manual.startimport.summary.dry-run.lock-field-for-all-objects'), t('pim.manual.startimport.summary.dry-run.lock-field-for-all-objects.confirm'), function (btn) {
                                            if (btn == 'yes' && field) {
                                                function processAllPages (store, processFn, page = 1) {
                                                    store.loadPage(page, {
                                                        callback: function (records, operation, success) {
                                                            if (success && records.length > 0) {
                                                                Ext.Array.each(records, processFn);
                                                                table.store.sync();

                                                                // If the number of records is less than the pageSize, it's the last page
                                                                if (records.length >= store.pageSize) {
                                                                    processAllPages(store, processFn, page + 1);
                                                                }
                                                            }
                                                        }
                                                    });
                                                }

                                                processAllPages(table.store, function (rec) {
                                                    if (rec.get('field') === field) {
                                                        rec.set('lock', true);
                                                    }
                                                });
                                            }
                                        });
                                    }
                                });

                                menu.add({
                                    text: t('pim.manual.startimport.summary.dry-run.ignore-all-values-for-all-objects'),
                                    icon: "/bundles/opendxpadmin/img/flat-color-icons/disapprove.svg",
                                    handler: function () {
                                        if (field) {
                                            function processAllPages (store, processFn, page = 1) {
                                                store.loadPage(page, {
                                                    callback: function (records, operation, success) {
                                                        if (success && records.length > 0) {
                                                            Ext.Array.each(records, processFn);
                                                            table.store.sync();

                                                            // If the number of records is less than the pageSize, it's the last page
                                                            if (records.length >= store.pageSize) {
                                                                processAllPages(store, processFn, page + 1);
                                                            }
                                                        }
                                                    }
                                                });
                                            }

                                            processAllPages(table.store, function (rec) {
                                                rec.set('lock', true);
                                            });

                                            table.store.sync();
                                        }
                                    }
                                });
                            }
                            menu.showAt(e.pageX, e.pageY);
                            e.stopEvent();
                        }
                    },
                    groupcontextmenu: function(table, record, group, e, eOpts) {
                        let allIgnored = true;
                        table.store.each(function (rec) {
                            if (rec.get('fullpath') === group && !rec.get('lock') && rec.get('new') !== 'unchanged') {
                                allIgnored = false;
                            }
                        });

                        var menu = new Ext.menu.Menu();
                        if(allIgnored) {
                            menu.add({
                                text: t('pim.manual.startimport.summary.dry-run.allow-all-fields-in-objects'),
                                icon: "/bundles/opendxpadmin/img/flat-color-icons/approve.svg",
                                hidden: !allIgnored,
                                handler: function () {
                                    if (group) {
                                        table.store.each(function (rec) {
                                            if (rec.get('fullpath') === group) {
                                                rec.set('lock', false);
                                            }
                                        });
                                        table.store.sync()
                                    }
                                }
                            });
                        } else {
                            menu.add({
                                text: t('pim.manual.startimport.summary.dry-run.lock-all-fields-in-objects'),
                                icon: "/bundles/opendxpadmin/img/flat-color-icons/disapprove.svg",
                                hidden: allIgnored,
                                handler: function () {
                                    if (group) {
                                        table.store.each(function (rec) {
                                            if (rec.get('fullpath') === group) {
                                                rec.set('lock', true);
                                            }
                                        });
                                        table.store.sync()
                                    }
                                }
                            });
                        }

                        menu.showAt(e.pageX, e.pageY);
                        e.stopEvent();
                    }
                },
                viewConfig: {
                    loadMask: this.getStartWindow().getComponent('startForm').getForm().findField('dry-run').getValue(),
                    emptyText: t('loading')+' ...',
                    enableTextSelection: true
                },
                dockedItems: [{
                    xtype: 'toolbar',
                    itemId: 'toolbar',
                    dock: 'top',
                    layout: {
                        type: 'fit',
                        align: 'stretch',
                        pack: 'start'
                    },
                    items: [
                        new Overridden.form.field.Tag({
                            fieldLabel: t('filter'),
                            store: Ext.create('Ext.data.Store', {
                                fields: ['type', 'label'],
                                data: [
                                    { type: 'unchanged', label: t('pim.manual.startimport.summary.changes.filter.unchanged'), customizable: false },
                                    { type: 'changed', label: t('pim.manual.startimport.summary.changes.filter.changed'), customizable: false },
                                    { type: 'new', label: t('pim.manual.startimport.summary.changes.filter.new'), customizable: false },
                                ]
                            }),
                            displayField: 'label',
                            value: ['changed','unchanged'],
                            valueField: 'type',
                            filterPickList: true,
                            forceSelection: false,
                            createNewOnEnter: true,
                            autoSelect: false,
                            labelWidth: 50,
                            queryMode: 'local',
                            anyMatch: true,
                            labelTpl: '<tpl if="type!==\'unchanged\' && type!==\'changed\' && type!==\'new\'">' + t('search') + ': </tpl><tpl if="customizable">{type}<tpl else>{label}</tpl>',
                            listeners: {
                                change: function (field, value) {
                                    var importPanel = summaryWindow.queryById('importPanel');
                                    importPanel.setLoading(true);
                                    importPanel.getStore().getProxy().setExtraParam("search[]", field.getValue());
                                    importPanel.getStore().load();
                                    importPanel.setLoading(false);

                                    toolbar.moveFirst();
                                }.bind(this),
                                focus: function (combo) {
                                    setTimeout(function () {
                                        if (!combo.isExpanded) {
                                            combo.expand();
                                        }
                                    }, 100);
                                }
                            }
                        })
                    ]}],
                bbar: toolbar,
            }),
            Ext.create('Ext.Panel', {
                itemId: 'exportIframe',
                title: t('pim.manual.startimport.summary.output'),
                collapsed: false,
                collapsible: true,
                scrollable: true,
                layout: {
                    type: 'fit',
                    align: 'stretch',
                    pack: 'start'
                },
                items: []
            })
        ];

        var summaryWindow = Ext.create('Ext.Window', {
            layout: {
                type: 'fit',
                align: 'stretch',
                pack: 'start'
            },
            itemId: 'startWindow' + this.dataportId,
            title: t('pim.manual.startimport.summary'),
            padding: 10,
            width: '80%',
            height: '80%',
            maximizable: true,
            minimizable: true,
            bodyPadding: 0,
            listeners: {
                minimize: function () {
                    var win = this;
                    win.toggleCollapse();
                    if (!win.getCollapsed()) {
                        win.center();
                    } else {
                        win.alignTo(document.body, 'bl-bl');
                    }
                }
            },
            dockedItems: [
                Ext.create('Ext.Panel', {
                    layout: {
                        type: 'hbox',
                        align: 'stretch'
                    },
                    items: [{
                        xtype: 'container',
                        itemId: 'progressBar',
                        flex: 1
                    }, {
                        xtype: 'button',
                        itemId: 'logExplorer',
                        icon: "/bundles/opendxpadmin/img/flat-color-icons/fine_print.svg",
                        height: 28,
                        padding: 0,
                        margin: '0 0 0 5px',
                        hidden: !opendxp.globalmanager.get("user").isAllowed('application_logging'),
                        href: '/admin/SylphenDataBridge/import/log/'+this.dataportId+'/' + statusKey,
                        hrefTarget: '_blank',
                        tooltip: t('pim.manual.startimport.summary.logs')
                    }]
                })
            ],
            items: [
                Ext.create('Ext.panel.Panel', {
                    itemId: 'accordion',
                    layout: {
                        type: 'accordion',
                        animate: false
                    },
                    items: panelItems
                })
            ]
        });
        summaryWindow.show();
    },

    getButtonTitle: function(value) {
        if (this.isMultiStepWizard) {
            return t('pim.manual.startimport.export.next')
        } else {
            return this.isExport ? t('pim.manual.startimport.'+ value+'.export') : t('pim.manual.startimport.'+value);
        }
    },

    rebuild: function () {
        var self = this;

        if(self.timeout) {
            clearTimeout(self.timeout);
        }

        this.removeAll();

        if (!opendxp.globalmanager.get("user").isAllowed('plugin_sylphen_data_bridge_admin_permission') && !opendxp.globalmanager.get("user").isAllowed('Dataport ' + this.dataportId + ' Execution')) {
            this.getComponent('toolbar').setVisible(false);
        }

        this.getComponent('toolbar').down('#start_rawdata_button').setText(this.isExport ? t('pim.manual.startimport.rawdata.export') : t('pim.manual.startimport.rawdata'));
        this.getComponent('toolbar').down('#start_rawdata_processing_button').setText(this.isExport ? t('pim.manual.startimport.pim.export') : t('pim.manual.startimport.pim'));
        this.getComponent('toolbar').down('#start_complete_button').setText(this.isExport ? t('pim.manual.startimport.complete.export') : t('pim.manual.startimport.complete'));

        var readerFields = [
            { name: 'id' },
            { name: 'file' },
            { name: 'sourceElementIDs' },
            { name: 'redoable' },
            { name: 'locale' },
            { name: 'startDate' },
            { name: 'endDate' },
            { name: 'percentage' },
            { name: 'status' },
            { name: 'type' },
            { name: 'logFile' },
            { name: 'worstLogType'},
            { name: 'worstLog' },
            { name: 'triggeredBy' }
        ]

        if(typeof JSONImportModel === "undefined") {
            Ext.define('JSONImportModel', {
                    extend: 'Ext.data.Model',
                    fields: readerFields,
                    idProperty: 'id'
                }
            );
        }

        var self = this;
        var store = Ext.create('Ext.data.JsonStore', {
            model: 'JSONImportModel',

            proxy: {
                type: 'ajax',
                url: '/admin/SylphenDataBridge/import/get-status/' + this.dataportId+((typeof Intl !== "undefined") ? '?timezone='+Intl.DateTimeFormat().resolvedOptions().timeZone : ''),
                reader: {
                    type: 'json',
                    rootProperty: 'status',
                    totalProperty: 'total',
                    messageProperty: 'message',
                    keepRawData: true
                },
                filterParam: 'query'
            },
            pageSize: 25,
            autoLoad: {start: 0, limit: 25},
            remoteFilter: true,

            listeners: {
                load: {
                    single: true,
                    fn: function() {
                        (function refresh() {
                            var response = store.getProxy().getReader().rawData;

                            try {
                                var queueProcessorButton = self.getComponent('toolbar').getComponent('queue_processing_error_button');

                                if (queueProcessorButton) {
                                    if (response.queueProcessingError || response.queueItemExists) {
                                        queueProcessorButton.show();
                                        if (response.queueProcessingError) {
                                            queueProcessorButton.setIconCls('opendxp_icon_warning');
                                            queueProcessorButton.setText(t('pim.manual.start_queue_processor'));
                                            queueProcessorButton.setTooltip(t('pim.manual.start_queue_processor.tooltip'));
                                        } else {
                                            queueProcessorButton.setIconCls('queue-processing-monitor-icon');
                                            queueProcessorButton.setText(t('pim.manual.queue_processor_monitor'));
                                            queueProcessorButton.setTooltip('');
                                        }
                                    } else {
                                        queueProcessorButton.hide();
                                    }
                                }
                            } catch(e) {}

                            self.timeout = setTimeout(function () {
                                if (document.hasFocus() && self.isVisible(true) && store.currentPage === 1 && (typeof window.getSelection === 'undefined' || window.getSelection().toString() === '')) {
                                    var storeExtraParams = store.getProxy().getExtraParams();
                                    if(!storeExtraParams.hasOwnProperty('search') || storeExtraParams.search === '') {
                                        store.on('load', function(store, records, successful) {
                                            if(successful) {
                                                refresh();
                                            } else {
                                                store.on('load', function (store, records, successful) {
                                                    if (successful) {
                                                        refresh();
                                                    }
                                                }, this, { single: true });
                                            }
                                        }, this, {single: true});
                                        store.load();
                                        return;
                                    }
                                }

                                refresh();
                            }, 1000);
                        })();
                    }
                }
            }
        });

        var toolbar = opendxp.helpers.grid.buildDefaultPagingToolbar(store, { pageSize: 25 });
        this.statusGrid = Ext.create('Ext.grid.Panel', {
            flex: 1,
            store: store,
            plugins: ['gridfilters'],
            cls: 'history-grid',
            columns: [
                {
                    header: t('pim.manual.statusgrid.startDate'),
                    width: 150,
                    dataIndex: 'startDate',
                    xtype: 'datecolumn',
                    format: 'd.m.Y H:i',
                    renderer: function (value) {
                        return value;
                    }.bind(this)
                },
                {
                    header: t('pim.manual.statusgrid.duration'),
                    width: 80,
                    dataIndex: 'duration',
                    renderer: function (value, meta, record) {
                        if(value === null) {
                            return '';
                        }

                        var timeParts = [];
                        timeParts.push(Math.floor(value / 3600).toString().padStart(2, '0'));
                        timeParts.push(Math.floor((value %= 3600) / 60).toString().padStart(2, '0'));
                        timeParts.push(Math.floor(value % 60).toString().padStart(2, '0'));

                        if(record.get('status') == 0) {
                            var percentageDone = parseInt(record.get('percentage'), 10);
                            if(percentageDone > 0) {
                                var remaining = value / percentageDone * 100 - value;
                                var remainingParts = [];
                                remainingParts.push(Math.floor(remaining / 3600).toString().padStart(2, '0'));
                                remainingParts.push(Math.floor((remaining %= 3600) / 60).toString().padStart(2, '0'));
                                remainingParts.push(Math.floor(remaining % 60).toString().padStart(2, '0'));
                                meta.tdAttr = 'data-qtip="' + t('pim.manual.statusgrid.duration.remaining') + ': ' + remainingParts.join(':') + '"';
                            }
                        }

                        return timeParts.join(':');
                    }.bind(this)
                },{
                    header: t('source'),
                    dataIndex: 'file',
                    flex: 1,
                    autoSizeColumn: true,
                    filter: {
                        type: 'list',
                        store: new Ext.data.JsonStore({
                            proxy: {
                                type: 'ajax',
                                url: '/admin/SylphenDataBridge/importconfig/get-dataport-resources/' + this.dataportId,
                                reader: {
                                    type: 'json',
                                    rootProperty: 'dataportResources'
                                }
                            },
                            fields: ['id', 'text']
                        })
                    }, renderer: function(value, meta, record) {
                        var file = value;
                        if (value == -1) {
                            file = '('+t('unknown')+')';
                        }

                        var sourceElementIds = record.get('sourceElementIDs');
                        if(sourceElementIds.length === 0) {
                            return file;
                        }

                        meta.tdAttr = 'data-qtip="' + t('pim.manual.statusgrid.click_to_open_archive_file') + '"';

                        return Ext.String.format('<a href="#" style="color:#00f">{0}</a>', file);
                    }
                },
                {
                    header: t('language'),
                    dataIndex: 'locale',
                    autoSizeColumn: true,
                    filter: {
                        active: false,
                        type: 'list',
                        store: new Ext.data.JsonStore({
                            proxy: {
                                type: 'ajax',
                                url: '/admin/SylphenDataBridge/importconfig/get-dataport-resource-locales/' + this.dataportId,
                                reader: {
                                    type: 'json',
                                    rootProperty: 'dataportResourceLocales'
                                }
                            },
                            fields: ['id', 'text']
                        })
                    }, renderer: function(value) {
                        if(value == -1) {
                            return '(' + t('unknown') + ')';
                        }
                        return value;
                    },
                    hidden: this.sourceType !== 'pimcore'
                },
                {
                    header: t('pim.manual.statusgrid.type'),
                    width: 200,
                    dataIndex: 'type',
                    renderer: function (v, metaData, record) {
                        var typeLabel = t('pim.manual.statusgrid.type.' + v);
                        if(record.get('dryRun')) {
                            typeLabel += ' - '+ t('pim.manual.startimport.dry-run.importType');
                        }
                        return typeLabel;
                    }
                },
                {
                    header: t('pim.manual.statusgrid.status'),
                    width: 150,
                    dataIndex: 'status',
                    renderer: function (v, meta, record) {
                        var status = t('pim.manual.statusgrid.status.' + v);
                        if (v==1 && record.get('worstLog')) {
                            var logType = record.get('worstLog').substr(1, record.get('worstLog').indexOf(']')-1);
                            status = t('pim.manual.statusgrid.status.'+logType);
                        }

                        if(record.get('worstLog') !== '') {
                            meta.tdAttr = 'data-qtip="' + record.get('worstLog').replaceAll('"', '&quot;') + '"';
                        } else if (record.get('triggeredBy') !== '') {
                            meta.tdAttr = 'data-qtip="Triggered by: ' + record.get('triggeredBy').replaceAll('"', '&quot;') + '"';
                        }

                        if(record.get('logFile') || v === 'queued') {
                            return Ext.String.format('<span style="text-decoration:underline;color:#0000EE">{0}</span>', status);
                        }

                        return status;
                    }
                },
                {
                    header: t('pim.manual.statusgrid.progress'),
                    width: 160,
                    dataIndex: 'percentage',
                    xtype: 'widgetcolumn',
                    onWidgetAttach: function (column, widget, record) {
                        widget.setValue(parseInt(record.get('percentage'), 10)/100);
                        widget.setText(record.get('percentage'));
                    },
                    widget: {
                        xtype: 'progressbarwidget'
                    }
                },
                {
                    xtype: 'actioncolumn',
                    width: 40,
                    items: [{
                        tooltip: t('pim.manual.importForm.cancel'),
                        icon: "/bundles/opendxpadmin/img/flat-color-icons/cancel.svg",
                        hidden: !opendxp.globalmanager.get("user").isAllowed('plugin_sylphen_data_bridge_admin_permission') && !opendxp.globalmanager.get("user").isAllowed('Dataport ' + this.dataportId + ' Execution'),
                        getClass: function (v, metadata, record) {
                            var status = record.get('status');
                            if (status != 0 && status !== 'queued') {
                                return 'x-hidden-display';
                            }
                            return '';
                        },
                        handler: function (grid, rowIndex) {
                            var record = grid.getStore().getAt(rowIndex);
                            Ext.Ajax.request({
                                url: '/admin/SylphenDataBridge/import/cancel/'+record.get('id'),
                                method: 'delete',
                                success: function (response) {
                                    try {
                                        response = Ext.decode(response.responseText);
                                        if (!(response && response.success)) {
                                            opendxp.helpers.showNotification(t("error"), t("pim.manual.importForm.cancel.failure"), "error", e.message);
                                        } else {
                                            store.load();
                                        }
                                    } catch (e) {
                                        opendxp.helpers.showNotification(t("error"), t("pim.manual.importForm.cancel.failure"), "error", e.message);
                                    }
                                }.bind(this)
                            });

                            Ext.query('td.x-action-col-cell img[src="/bundles/opendxpadmin/img/flat-color-icons/refresh.svg"]', grid.getNode(rowIndex))[rowIndex].src = "/bundles/sylphendatabridge/img/loading-gif.gif";
                            store.load();
                        }.bind(this)
                    },
                    {
                        tooltip: t('pim.manual.importForm.redo'),
                        icon: "/bundles/opendxpadmin/img/flat-color-icons/refresh.svg",
                        hidden: !opendxp.globalmanager.get("user").isAllowed('plugin_sylphen_data_bridge_admin_permission') && !opendxp.globalmanager.get("user").isAllowed('Dataport ' + this.dataportId + ' Execution'),
                        getClass: function (v, metadata, record) {
                            if (!record.get('redoable') || record.get('status') == 0) {
                                return 'x-hidden-display';
                            }
                            return '';
                        },
                        handler: function (grid, rowIndex) {
                            var record = grid.getStore().getAt(rowIndex);

                            var redoRequest = {
                                method: 'post',
                                params: {
                                    dataportId: this.dataportId,
                                    statusKey: record.get('id'),
                                    force: 1
                                },
                                success: function (response) {
                                    response = Ext.decode(response.responseText);
                                    if (response.url) {
                                        var popup = window.open(response.url);
                                        if (!popup || popup.closed || typeof popup.closed == 'undefined') {
                                            Ext.MessageBox.alert(t('error'), response.msg);
                                        }
                                    } else if(typeof response.statusKey !== "undefined") {
                                        this.showSummaryWindow(response.statusKey);
                                    } else {
                                        opendxp.helpers.showNotification(t("success"), t('pim.manual.importForm.success'), 'success');
                                    }
                                }.bind(this)
                            };

                            if(record.get('type') == 0) {
                                redoRequest.params.importType = 'raw';
                                redoRequest.url = '/admin/SylphenDataBridge/import/manual-import';
                            } else if(record.get('type') == 2) {
                                redoRequest.params.importType = 'complete';
                                redoRequest.url = '/admin/SylphenDataBridge/import/manual-import';
                            } else if (record.get('type') == 1 || record.get('type') == 3) {
                                redoRequest.url = '/admin/SylphenDataBridge/import/manual-pim-import';
                            }

                            Ext.Ajax.request(redoRequest);

                            Ext.query('td.x-action-col-cell img[src="/bundles/opendxpadmin/img/flat-color-icons/refresh.svg"]', grid.getNode(rowIndex))[rowIndex].src= "/bundles/sylphendatabridge/img/loading-gif.gif";

                            store.load();
                        }.bind(this)
                    }]
                }
            ],
            bbar: toolbar,
            listeners: {
                cellclick: function (grid, tdElement, columnIndex, record) {
                    var dataIndex = grid.getHeaderCt().getHeaderAtIndex(columnIndex).dataIndex;
                    if (dataIndex === 'file') {
                        var sourceElementIds = record.get('sourceElementIDs');

                        Ext.each(sourceElementIds, function (sourceElementId) {
                            opendxp.helpers.openElement(sourceElementId, 'asset');
                        });
                    } else if(dataIndex === 'status') {
                        if(record.get('logFile')) {
                            this.showSummaryWindow(record.get('logFile').replace(this.dataportId + '/', ''));
                        } else if(record.get('status') === 'queued') {
                            opendxp.helpers.openGenericIframeWindow("data-bridge-queue-monitor", "/admin/SylphenDataBridge/import/start-queue-processing", "queue-processing-monitor-icon", "Data Bridge Queue Monitor");
                        }
                    }
                }.bind(this), itemcontextmenu: function (view, record, item, index, e) {
                    if (record.get('logFileAbsolutePath')) {
                        var xPos = e.getXY()[0];
                        var cols = view.getGridColumns();

                        for (var c in cols) {

                            var leftEdge = cols[c].getPosition()[0];
                            var rightEdge = cols[c].getSize().width + leftEdge;

                            if (xPos >= leftEdge && xPos <= rightEdge) {
                                if(cols[c].dataIndex === 'status') {
                                    var menu = new Ext.menu.Menu();

                                    menu.add(new Ext.menu.Item({
                                        text: t('pim.dataport.copy-filesystem-path'),
                                        icon: "/bundles/opendxpadmin/img/flat-color-icons/paste.svg",
                                        handler: function (menuItem, e) {
                                            opendxp.helpers.copyStringToClipboard(record.get('logFileAbsolutePath'));
                                        }.bind(this),
                                    }));

                                    menu.showAt(e.getXY());

                                    e.stopEvent();
                                }
                            }
                        }
                    }
                }.bind(this)
            },

            border: 0,
            disableSelection: true,
            viewConfig: {
                loadMask: false,
                preserveScrollOnReload: true,
                enableTextSelection: true,
                getRowClass: function (record, rowIndex, rowParams, store) {
                    return (record.get('worstLogType')) ? 'log-'+record.get('worstLogType') : '';
                }
            }
        });

        this.add(this.statusGrid);
        opendxp.layout.refresh();
    }
});
