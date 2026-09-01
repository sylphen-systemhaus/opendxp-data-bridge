/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


opendxp.registerNS("opendxp.plugin.Pim.MappingConfig");

opendxp.plugin.Pim.MappingPanel = Ext.extend(Ext.grid.Panel, {
    dataportId: null,

    dataportPanel: null,
    callbackLanguage: null,
    languageStore: null,
    isExport: false,

    layout: {
        type: 'fit',
        align: 'stretch',
        pack: 'start'
    },

    initComponent: function () {
        var mappingPanel = this;

        mappingPanel.languageStore = Ext.create('Ext.data.JsonStore', {
            proxy: {
                type: 'ajax',
                url: '/admin/SylphenDataBridge/mappingconfig/get-autotranslate-languages',
                reader: {
                    type: 'json',
                    rootProperty: 'languages'
                }
            },
            fields: ['text', 'value', 'defaultLanguage'],
            autoLoad: false,
            listeners: [{
                beforeload: function(store) {
                    return store.count() === 0;
                }
            }]
        });
        mappingPanel.languageStore.load();

        if(typeof MappingConfigModel === 'undefined') {
            Ext.define('MappingConfigModel', {
                extend: 'Ext.data.Model',
                idProperty: 'attributeKey',

                fields: [
                    {
                        name: 'attributeName',
                        type: 'string'
                    },
                    {
                        name: 'type',
                        type: 'string'
                    },
                    {
                        name: 'field',
                        type: 'int',
                        allowNull: true
                    },
                    {
                        name: 'settings',
                        type: 'auto'
                    },
                    {
                        name: 'example',
                        type: 'auto',
                        persist: false
                    },
                    {
                        name: 'example_parsed',
                        type: 'auto',
                        persist: false
                    },
                    {
                        name: 'example_value',
                        type: 'auto',
                        persist: false
                    },
                    {
                        name: 'templates',
                        type: 'auto',
                        persist: false
                    },
                    {
                        name: 'rawValue',
                        type: 'auto',
                        persist: false
                    },
                    {
                        name: 'rawItemData',
                        type: 'auto',
                        persist: false
                    },
                    {
                        name: 'currentValue',
                        type: 'auto',
                        persist: false
                    },
                    {
                        name: 'currentObjectData',
                        type: 'auto',
                        persist: false
                    },
                    {
                        name: 'virtualFields',
                        type: 'auto',
                        persist: false
                    },
                    {
                        name: 'keyValues',
                        type: 'auto',
                        persist: false
                    },
                    {
                        name: 'output',
                        type: 'auto',
                        persist: false
                    },
                    {
                        name: 'unique',
                        type: 'auto',
                        persist: false
                    },
                    {
                        name: 'history',
                        type: 'auto',
                        persist: false
                    }
                ]
            });
        }

        var store = Ext.create('Ext.data.Store', {
            model: 'MappingConfigModel',
            pageSize: 0,
            remoteFilter: false,
            remoteSort: true,
            autoSync: false,
            listeners: {
                load: function() {
                    searchField.fireEvent('keyup', searchField);

                    if(this.dataportPanel) {
                        this.dataportPanel.sourceConfigRawitemPanel.getView().refresh();
                    }
                }.bind(this),
                write: function(store, operation) {
                    Ext.each(operation.getRecords(), function(record) {
                        var virtualFieldPrefix = record.get('attributeKey').indexOf('__virtual_');
                        var fieldName = record.get('attributeKey');
                        if (virtualFieldPrefix === 0) {
                            fieldName = fieldName.substr('__virtual_'.length);
                        }
                        var fieldGetsUsedInCallbackFunctionRegex = new RegExp('{{\\s*' + fieldName.replace(new RegExp('[.\\\\+*?\\[\\^\\]$(){}=!<>|:\\-]', 'g'), '\\$&') + '\\s*}}');

                        var reloadStore = false;
                        var settings = record.get('settings');
                        if(settings.calculation) {
                            reloadStore = settings.calculation.indexOf('{{') !== -1;
                        }

                        if(!reloadStore) {
                            this.getStore().each(function (rec) {
                                if (rec.get('settings').calculation && fieldGetsUsedInCallbackFunctionRegex.test(rec.get('settings').calculation)) {
                                    reloadStore = true;
                                    return false;
                                }
                            });
                        }

                        if (reloadStore) {
                            this.getStore().load();
                        } else {
                            this.dataportPanel.sourceConfigRawitemPanel.getView().refresh();
                        }
                    }.bind(this));
                }.bind(this)
            },

            proxy: {
                type: 'ajax',
                simpleSortMode: true,
                api: {
                    read: '/admin/SylphenDataBridge/mappingconfig/get-config/' + mappingPanel.dataportId,
                    create: '/admin/SylphenDataBridge/mappingconfig/save-config',
                    update: '/admin/SylphenDataBridge/mappingconfig/save-config'
                },
                reader: {
                    type: 'json',
                    rootProperty: 'mapping',
                    totalProperty: 'totalCount',
                    transform: function (data) {
                        if(data) {
                            if (!data.success) {
                                opendxp.helpers.showNotification(t("error"), data.errorMessage, 'error');
                            } else {
                                Ext.each(data.mapping, function (mapping) {
                                    mapping.rawItemData = data.rawItemData;
                                    mapping.currentObjectData = data.currentObjectData;
                                    mapping.virtualFields = data.virtualFields;
                                });
                            }
                        } else {
                            Ext.Msg.show({
                                title: t('pim_error_loadmapping'),
                                msg: t('pim.error'),
                                buttons: Ext.Msg.YESNO,
                                buttonText: {
                                    yes: t('pim.conflict.check_versions'),
                                    no: t('pim.error.retry'),
                                },
                                fn: function (btn) {
                                    if (btn === 'yes') {
                                        this.dataportPanel.configPanel.queryById('versionButton').fireHandler();
                                    } else if (btn === 'no') {
                                        store.load();
                                    }
                                }.bind(this),
                                icon: Ext.MessageBox.ERROR,
                            });
                        }

                        return data;
                    }.bind(this),
                    messageProperty: 'errorMessage'
                },

                writer: {
                    type: 'json',
                    writeAllFields: true,
                    rootProperty: 'mapping',
                    encode: true,
                    transform: {
                        fn: function (data, request) {
                            request.setParam('dataportId', this.dataportId);
                            request.setParam('lastModified', this.dataportPanel.dataport.modificationDate);

                            if (Ext.isArray(data)) {
                                Ext.Array.each(data, function (value) {
                                    value['attribute.key'] = value.attribute.key;
                                });
                            } else {
                                data['attribute.key'] = data.attribute.key;
                            }

                            return data;

                        },
                        scope: this
                    }
                },
                listeners: {
                    exception: function (proxy, response, operation, eOpts) {
                        var responseJson = response.responseJson;
                        if(typeof responseJson === "undefined") {
                            responseJson = JSON.parse(response.responseText);
                        }

                        if(!responseJson) {
                            if(response.status == 500) {
                                Ext.Msg.show({
                                    title: t('pim_error_loadmapping'),
                                    msg: t('pim.error') + ': ' + response.statusText,
                                    buttons: Ext.Msg.YESNO,
                                    buttonText: {
                                        yes: t('pim.conflict.check_versions'),
                                        no: t('pim.error.retry'),
                                    },
                                    fn: function (btn) {
                                        if (btn === 'yes') {
                                            this.dataportPanel.configPanel.queryById('versionButton').fireHandler();
                                        } else if (btn === 'no') {
                                            store.load();
                                        }
                                    }.bind(this),
                                    icon: Ext.MessageBox.ERROR,
                                });
                            } else {
                                opendxp.helpers.showNotification(t("error"), t('pim_error_loadmapping'), 'error');
                            }
                        } else if (!responseJson.success && typeof responseJson.lastModified !== "undefined" && responseJson.lastModified) {
                            var messageBox = new Ext.window.MessageBox();
                            messageBox.show({
                                title: t('pim.conflict.title'),
                                msg: t('pim.conflict').replace('%s', responseJson.lastModifiedUser),
                                buttons: Ext.Msg.OK & Ext.Msg.YES & Ext.Msg.NO,
                                buttonText: { ok: t('pim.conflict.force'), yes: t('pim.conflict.check_versions'), no: t('pim.conflict.cancel') },
                                prompt: false,
                                icon: Ext.MessageBox.QUESTION,
                                fn: function (action) {
                                    if (action === 'ok') {
                                        this.dataportPanel.dataport.modificationDate = responseJson.lastModified;

                                        if(this.queryById('saveButton').isHidden()) {
                                            store.sync();
                                        }
                                    } else if (action === 'yes') {
                                        this.dataportPanel.configPanel.queryById('versionButton').fireHandler();
                                    } else if (action === 'no' || action === 'cancel') {
                                    }
                                }.bind(this)
                            });
                        }
                    }.bind(this)
                }
            }
        });
        store.proxy.setTimeout(300000);
        store.load();

        var searchMapping = function() {
            store.clearFilter();

            store.each(function (rec) {
                rec.matches = false;

                for (var field of ['attributeKey', 'attributeName', 'example', 'example_parsed', 'output']) {
                    var value = rec.get(field);
                    if (typeof value === 'string') {
                        if (value.toLowerCase().indexOf(searchField.getValue().toLowerCase()) > -1) {
                            rec.matches = true;
                            break;
                        }
                    }
                }

                if (!rec.matches && rec.get('settings').calculation && rec.get('settings').calculation.toLowerCase().indexOf(searchField.getValue().toLowerCase()) > -1) {
                    rec.matches = true;
                }

                if(!rec.matches && rec.get('field')) {
                    var rawItemRecord = rawItemFieldStore.findRecord('fieldNo', rec.get('field'));
                    if(rawItemRecord && rawItemRecord.get('name').toLowerCase().indexOf(searchField.getValue().toLowerCase()) > -1) {
                        rec.matches = true;
                    }
                }

                if(rec.matches && showOnlyMappedFieldsCheckbox.getValue() && !rec.get('field') && !rec.get('settings').calculation) {
                    rec.matches = false;
                }
            });

            store.filterBy(function (rec, id) {
                return rec.matches;
            });
        }

        var searchField = Ext.create('Ext.form.field.Text', {
            fieldLabel: t('search'),
            labelWidth: 50,
            enableKeyEvents: true,
            triggers: {
                search: {
                    weight: 1,
                    cls: 'x-form-search-trigger',
                    handler: function () {
                        searchMapping();
                    }.bind(this)
                }
            },
            listeners: {
                keyup: function () {
                    searchMapping();
                }
            }
        });
        var showOnlyMappedFieldsCheckbox = Ext.create('Ext.form.field.Checkbox', {
            boxLabel: t('pim.mapping.show_only_mapped'),
            style: "margin-bottom: 5px; margin-left: 5px",
            checked: false,
            listeners: {
                change: function (checkbox, checked) {
                    searchMapping();
                }
            }
        });

        var rawItemFieldStore = Ext.create('Ext.data.ChainedStore', { source: this.dataportPanel.sourceConfigRawitemPanel.store, sorters: [
            {
                sorterFn: function (record1, record2) {
                    var name1,name2;
                    try {
                        name1 = record1.get('name').toLowerCase();
                    } catch(e) {
                        name1 = '';
                    }

                    try {
                        name2 = record2.get('name').toLowerCase();
                    } catch (e) {
                        name2 = '';
                    }

                    return name1 > name2 ? 1 : (name1 === name2) ? 0 : -1;
                }
            }
        ]});

        var alternativeRawItemEditor = {
            xtype: 'combobox',
            store: Ext.create('Ext.data.JsonStore', {
                proxy: {
                    type: 'ajax',
                    url: '/admin/SylphenDataBridge/mappingconfig/get-alternative-raw-item-data',
                    extraParams: {
                        dataportId: this.dataportId
                    },
                    reader: {
                        type: 'json',
                        rootProperty: 'alternatives'
                    }
                },
                fields: ['rawItemId', 'value'],
                autoLoad: false
            }),
            anyMatch: true,
            displayField: 'value',
            valueField: 'rawItemId',
            queryMode: 'local',
            forceSelection: true,
            allowBlank: false,
            matchFieldWidth: false,
            listeners: {
                select: function (combo, value) {
                    combo.ownerCt.completeEdit();
                    Ext.util.Cookies.set('rawItem-'+this.dataportId, value.get('rawItemId'));
                    store.load();
                }.bind(this),
                focus: function (combo) {
                    setTimeout(function () {
                        if (!combo.isExpanded) {
                            combo.expand();
                        }
                    }, 100);
                }
            }
        };

        Ext.apply(this, {
            closable: false,
            trackMouseOver: true,
            store: store,
            clicksToEdit: 1,
            columnLines: true,
            stripeRows: true,
            selModel: {
                selType: 'rowmodel',
                mode: 'MULTI'
            },
            multiSelect: true,
            buttons: [
                {
                    text: t("save"),
                    itemId: 'saveButton',
                    iconCls: "opendxp_icon_save",
                    handler: function(button) {
                        this.getStore().sync();
                        button.hide();
                    }.bind(this),
                    hidden: true
                }
            ],
            dockedItems: [{
                dock: 'top',
                xtype: 'toolbar',
                items: [
                    searchField,
                    showOnlyMappedFieldsCheckbox,
                    '->',
                    {
                        iconCls: "opendxp_icon_reload",
                        handler: function () {
                            store.load();
                        }.bind(this),
                        tooltip: t('reload'),
                    },
                    {
                        xtype: 'button',
                        text: t('pim.mapping.auto_map'),
                        iconCls: "opendxp_icon_clear_cache",
                        tooltip: t('pim.mapping.auto_map.tooltip'),
                        hidden: !opendxp.globalmanager.get("user").isAllowed('plugin_sylphen_data_bridge_admin_permission') && !opendxp.globalmanager.get("user").isAllowed('Dataport ' + this.dataportId + ' Configuration'),
                        handler: function () {
                            var rawDataFields = Ext.Array.pluck((this.dataportPanel.sourceConfigRawitemPanel.store.getData().getSource() || this.dataportPanel.sourceConfigRawitemPanel.store.getData()).getRange(), 'data');

                            var targetFields = [];

                            Ext.each(store.getData().getRange(), function (mappingRecord) {
                                if(!mappingRecord.get('field') && !mappingRecord.get('settings').calculation && ['__result_callback', '__result_action', '__init_action'].indexOf(mappingRecord.get('attribute').key) === -1 && mappingRecord.get('attribute').key !== 'id') {
                                    targetFields.push({
                                        fieldName: mappingRecord.get('attributeKey'),
                                        brickName: mappingRecord.get('brickName'),
                                        targetBrickField: mappingRecord.get('targetBrickField')
                                    });
                                }
                            }.bind(this));

                            Ext.Ajax.request({
                                url: '/admin/SylphenDataBridge/mappingconfig/auto-assign-rawdata-fields',
                                params: {
                                    itemClass: this.dataportPanel.dataportPanel.getForm().findField('itemClass').getValue(),
                                    dataportId: this.dataportId
                                },
                                method: 'post', // for classes with many fields GET length limit of 2048 characters would be exceeded
                                success: function (response) {
                                    response = Ext.decode(response.responseText);

                                    var unmappedFields = rawDataFields;
                                    for(var targetField in response.mappingProposals) {
                                        if(!response.mappingProposals.hasOwnProperty(targetField)) {
                                            continue;
                                        }
                                        var record = store.findRecord('attributeKey', targetField, 0, false, true, true);
                                        if(record !== null) {
                                            var settings = record.get('settings');
                                            settings.calculation = response.mappingProposals[targetField].calculation;
                                            settings.keyMapping = response.mappingProposals[targetField].keyField;
                                            var setFields = {
                                                field: response.mappingProposals[targetField].field,
                                                settings: settings
                                            };
                                            record.set(setFields);

                                            this.queryById('saveButton').show();
                                        }
                                    }

                                    unmappedFields = Ext.Array.filter(unmappedFields, function (unmappedField) {
                                        var recordIndex = store.findExact('field', unmappedField.fieldNo);
                                        return recordIndex === -1;
                                    }.bind(this));

                                    if(unmappedFields.length > 0) {
                                        var unmappedFieldNames = Ext.Array.map(unmappedFields, function(unmappedField) {
                                            return unmappedField.name;
                                        });
                                        opendxp.helpers.showNotification(t("info"), t('pim.mapping.auto_map.success')+'.<br>'+t('pim.mapping.auto_map.unmapped_fields').replace('%s', '<ul><li>'+unmappedFieldNames.join('</li><li>')+'</li></ul>') + '<br><br>' + t('pim.mapping.auto_map.success.save_hint'), 'info');
                                    } else {
                                        opendxp.helpers.showNotification(t("success"), t('pim.mapping.auto_map.success')+'<br><br>'+t('pim.mapping.auto_map.success.save_hint'), 'success');
                                    }
                                }.bind(this)
                            });
                        }.bind(this)
                    }
                ]
            }],
            viewConfig: {
                loadMask: true,
                preserveScrollOnReload: true,
                //enableTextSelection: true,
                plugins: {
                    ptype: 'gridviewdragdrop',
                    containerScroll: true,
                    ddGroup: 'dd_mapping_' + this.dataportId,
                    enableDrop: false,
                    dragZone: {
                        onBeforeDrag: function (data, e) {
                            draggedCell = Ext.get(e.target.parentNode);
                            if (!draggedCell.hasCls('supportDnD')) {
                                return false;
                            }
                        }
                    }
                },
                listeners: {
                    render: function (el) {
                        var dd = new Ext.dd.DropZone(el.getEl().dom, {
                            ddGroup: 'dd_mapping_' + this.dataportId,

                            getTargetFromEvent: function (e) {
                                return e.getTarget();
                            },

                            onNodeOver: function (target, dd, e, data) {
                                return Ext.dd.DropZone.prototype.dropAllowed;
                            },

                            onNodeDrop: function (target, dd, e, data) {
                                var targetRecord = store.findRecord('attributeKey', target.closest('tr').children[0].getAttribute('data-attributeKey'));
                                if(targetRecord) {
                                    var setFields = {
                                        field: data.records[0].get('field'),
                                        settings: data.records[0].get('settings')
                                    };
                                    targetRecord.set(setFields);
                                    store.sync();
                                    return true;
                                }

                                return false;
                            }.bind(this)
                        })
                    }.bind(this),
                    refresh: function(dataview) {
                        Ext.each(dataview.panel.columns, function(column) {
                            if (column.autoSizeColumn === true) {
                                column.autoSize();
                            }
                        })
                    }
                },
            },
            plugins: [
                Ext.create('Ext.grid.plugin.CellEditing', { clicksToEdit: 1 })
            ],
            listeners: {
                cellclick: function (grid, tdElement, columnIndex, record) {
                    var dataIndex = grid.getHeaderCt().getHeaderAtIndex(columnIndex).dataIndex;
                    if (dataIndex === 'example') {
                        grid.editingPlugin.startEdit(record, columnIndex);
                    } else if(dataIndex === 'attributeName' && record.get('attributeKey') !== '__result_action' && record.get('attributeKey') !== '__result_callback') {
                        var nameParts = /((\w+)\/)?(\w+)(#(\w+))?/.exec(record.get('attributeKey'));

                        var brickName = nameParts[2];
                        var locale = nameParts[5];
                        var field = nameParts[3];

                        var urlParams = 'field='+field.replace(/^__virtual_/, '');
                        if(locale) {
                            urlParams += '&locale='+locale;
                        }
                        if (brickName) {
                            urlParams += '&brickName='+brickName;
                        }

                        var dependencyWindow = Ext.create('Ext.window.MessageBox', {
                            itemId: 'dependencyWindow',
                            resizable: true,
                            maxWidth: '100%',
                            maxHeight: '100%',
                            closeAction: 'destroy'
                        });

                        dependencyWindow.on('show', function() {
                            dependencyWindow.getEl().down('object').on('load', function () {
                                dependencyWindow.updateLayout();
                            });
                            dependencyWindow.getEl().down('img').on('load', function () {
                                dependencyWindow.updateLayout();
                            });
                        });

                        var targetClass = this.dataportPanel.dataportPanel.getForm().findField('itemClass').getValue();
                        if((!targetClass || targetClass === "0") && this.dataportPanel.sourceConfigBasePanel.getForm().findField('sourceClass')) {
                            targetClass = this.dataportPanel.sourceConfigBasePanel.getForm().findField('sourceClass').getValue();
                        }
                        dependencyWindow.show({
                            title: t("pim.mapping.visualize_dependencies").replace('%s', record.get('attributeName')),
                            msg: '<object data="/admin/SylphenDataBridge/mappingconfig/visualize-dependencies?format=svg&targetClass='+targetClass+'&'+urlParams+'" type="image/svg+xml">' +
                              '  <img src="/admin/SylphenDataBridge/mappingconfig/visualize-dependencies?format=png&targetClass='+targetClass+'&' + urlParams + '" />' +
                              '</object>',
                            icon: Ext.MessageBox.SUCCESS,
                            buttons: Ext.Msg.OK,
                            width: '80%',
                            height: '80%'
                        });
                    } else if(grid.getHeaderCt().getHeaderAtIndex(columnIndex).text == t('pim.transformations')) {
                        mappingPanel.showSettingsWindow(record);
                    } else if(grid.getHeaderCt().getHeaderAtIndex(columnIndex).text == t('pim.mapping.example.parsed')) {
                        opendxp.helpers.copyStringToClipboard(tdElement.innerText);
                        opendxp.helpers.showNotification(t("info"), t('pim.mapping.copied_to_clipboard_mapping'), 'info');
                    }
                }.bind(this),
                beforeedit: function(editor, context, eOpts ) {
                    if (!opendxp.globalmanager.get("user").isAllowed('plugin_sylphen_data_bridge_admin_permission') && !opendxp.globalmanager.get("user").isAllowed('Dataport ' + this.dataportId + ' Configuration')) {
                        return false;
                    }

                    if(context.field === 'example') {
                        context.column.getEditor().getStore().getProxy().setExtraParam("fieldNo", context.record.get('field'));
                        context.column.getEditor().getStore().load();
                    }

                    return context.field !== 'field' || ['__result_callback', '__result_action', '__init_action'].indexOf(context.record.get('attributeKey')) === -1;
                }.bind(this),
                itemcontextmenu: function (view, record, item, index, e) {
                    var menu = new Ext.menu.Menu();

                    var selectedRows = this.getSelectionModel().getSelection();
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
                        text: t('pim.mapping.remove_mapping'),
                        iconCls: "opendxp_icon_delete",
                        handler: function () {
                            Ext.each(records, function(record) {
                                var setFields = {};
                                var settings = JSON.parse(JSON.stringify(record.get('settings'))); // clone
                                setFields.settings = settings;

                                setFields.field = null;
                                setFields.settings.calculation = '';
                                record.set(setFields);
                            });

                            if (this.queryById('saveButton').isHidden()) {
                                store.sync();
                            }
                        }.bind(this)
                    }));

                    menu.showAt(e.getXY());

                    e.stopEvent();
                }.bind(this)
            },
            columns: [
                {
                    header: t('pim.mapping.target_class_field'),
                    dataIndex: 'attributeName',
                    groupable: false,
                    autoSizeColumn: true,
                    minWidth: 220,
                    renderer: function (value, metaData, record) {
                        var fieldType = record.get('type');
                        if (typeof opendxp.object.classes.data[record.get('type')] !== "undefined") {
                            fieldType = opendxp.object.classes.data[record.get('type')].prototype.getTypeName();
                        }

                        var tooltip = '';
                        if (record.get('attributeKey').indexOf('__virtual_') === 0) {
                            tooltip = t('pim.mapping.virtual_field.tooltip');
                        } else if (record.get('attributeKey').toLowerCase() !== value.toLowerCase()) {
                            if(record.get('attributeKey') === '__result_callback') {
                                tooltip = t('pim.mapping.result_callback.hint');
                            } else if (record.get('attributeKey') === '__result_action') {
                                tooltip =  t('pim.mapping.result_action.hint') ;
                            } else if (record.get('attributeKey') === '__init_action') {
                                tooltip = 'data-qtip="' + t('pim.mapping.init_action.hint') + '"';
                            } else {
                                tooltip = record.get('attributeKey')+' ('+ fieldType+')';
                            }
                        } else {
                            tooltip = fieldType;
                        }

                        if(['__result_callback', '__result_action', '__init_action'].indexOf(record.get('attributeKey')) > -1) {
                            return '<'+(this.isExport?'span':'i')+' class="opendxp_icon_snippet" style="background-position-x:left !important;padding-left:26px;font-weight:600;">'+value+'</i>';
                        }

                        if(record.get('attributeKey').indexOf('__virtual_') === 0) {
                            return '<' + (this.isExport ? 'span' : 'i') + ' class="opendxp_icon_plugin" style="background-position-x:left !important;padding-left:26px;">' + value + '</>';
                        }

                        if (record.get('attributeKey') === 'id') {
                            tooltip = t('pim.mapping.id.tooltip');
                        } else if (record.get('attributeKey') === 'key') {
                            tooltip = t('pim.mapping.key.tooltip');
                            value = t('pim.mapping.key');
                        } else if (record.get('attributeKey') === 'delete element') {
                            tooltip = t('pim.mapping.deleteElement.tooltip');
                            value = '<i>' + t('pim.mapping.deleteElement') + '</i>';
                        } else if (record.get('attributeKey') === 'Complete Object') {
                            tooltip = t('pim.mapping.completeObject.tooltip');
                            value = '<i>' + t('pim.mapping.completeObject') + '</i>';
                        } else if (record.get('attributeKey') === 'Stream') {
                            tooltip = 'Stream';
                            value = t('pim.mapping.stream');
                        }

                        var localizedFieldIcon = '';
                        if (record.get('locale')) {
                            localizedFieldIcon = '<div style="float:right;" class="x-action-col-icon opendxp_icon_language_' + record.get('locale').toLowerCase() + '"></div>';
                            value = value.substring(0, value.indexOf('#'));

                            tooltip += ', ' + t(typeof opendxp.available_languages[record.get('locale')] === "undefined" ? record.get('locale') : opendxp.available_languages[record.get('locale')]);
                        }

                        if (record.get('attributeKey') !== '__result_action' && record.get('attributeKey') !== '__result_callback') {
                            tooltip += '<br>'+t('pim.mapping.visualize_dependencies.tooltip');
                        }

                        if(tooltip) {
                            metaData.tdAttr = 'data-qtip="' + tooltip + '"';
                        } else {
                            metaData.tdAttr = '';
                        }

                        metaData.tdAttr += 'data-attributeKey="'+ record.get('attributeKey') +'"';

                        if (typeof opendxp.object.classes.data[record.get('type')] !== "undefined") {
                            var iconClass = opendxp.object.classes.data[record.get('type')].prototype.getIconClass();

                            if (iconClass) {
                                return localizedFieldIcon+'<span class="' + iconClass + '" style="background-position-x:left !important;padding-left:26px">' + value + '</span>';
                            }
                        }
                        return localizedFieldIcon+'<span style="padding-left:26px">' + value + '</span>';
                    }.bind(this),
                },
                {
                    header: t('pim.rawdatafield'),
                    dataIndex: 'field',
                    autoSizeColumn: true,
                    tdCls: 'supportDnD',
                    renderer: function (value) {
                        if (value) {
                            if(rawItemFieldStore.isLoading()) {
                                rawItemFieldStore.on('load', function() {
                                    this.getView().refresh();
                                }, this, {single: true});
                            }

                            var foundRecord = null;
                            Ext.each((rawItemFieldStore.getData().getSource() || rawItemFieldStore.getData()).getRange(), function (record) {
                                if(record.get('fieldNo') == value) {
                                    foundRecord = record;
                                    return false;
                                }
                            });
                            if (foundRecord !== null) {
                                return foundRecord.get('name');
                            } else {
                                return t('pim.rawdatafield') + ' ' + value;
                            }
                        } else {
                            return "";
                        }
                    }.bind(this),
                    editor: {
                        xtype        : 'combo',
                        store        : rawItemFieldStore,
                        anyMatch     : true,
                        displayField : 'name',
                        valueField   : 'fieldNo',
                        queryMode    : 'local',
                        forceSelection: true,
                        allowBlank: true,
                        matchFieldWidth: false,
                        listConfig: {
                            tpl: Ext.create('Ext.XTemplate',
                              '<tpl for=".">',
                              '<div role="option" class="x-boundlist-item" title="{description}">{name}{[this.getDemoData(values)]}</div>',
                              '</tpl>',
                              {
                                  getDemoData: function (v) {
                                      var foundRecord = null;
                                      Ext.each((rawItemFieldStore.getData().getSource() || rawItemFieldStore.getData()).getRange(), function (record) {
                                          if (record.get('fieldNo') == v.fieldNo) {
                                              foundRecord = record;
                                              return false;
                                          }
                                      });

                                      if (foundRecord === null) {
                                          return '';
                                      }
                                      var demoData = foundRecord.get('data1');
                                      if (demoData === '' || demoData === t('loading')+' ...') {
                                          return '';
                                      }

                                      if(demoData === null) {
                                          demoData = '<i>null</i>';
                                      }

                                      if(demoData.length > 50) {
                                          demoData = demoData.substr(0, 47)+' ...';
                                      }
                                      return ' (e.g. '+demoData+')';
                                  }
                              }
                            )
                        },
                        displayTpl: [
                            '<tpl for=".">',
                            '{name}',
                            '</tpl>'
                        ],
                        listeners: {
                            select: function(combo) {
                                combo.ownerCt.completeEdit();
                                if (this.queryById('saveButton').isHidden()) {
                                    store.sync();
                                }
                            }.bind(this),
                            beforeSelect: function(combo) {
                                var allRecords = combo.up('editor').context.store.queryBy(function () { return true; }).getRange();
                                var hasKeyField = false;
                                Ext.each(allRecords, function(record) {
                                    var settings = record.get('settings');
                                    if(settings.keyMapping) {
                                        hasKeyField = true;
                                        return false;
                                    }
                                });

                                if(!hasKeyField) {
                                    var currentRecord = combo.up('editor').context.record;
                                    if (currentRecord.get('unique')) {
                                        var settings = currentRecord.get('settings');
                                        settings.keyMapping = true;
                                        currentRecord.set('settings', settings);
                                    }
                                }
                            }.bind(this),
                            specialkey: function(combo, e){
                                if (e.getKey() == e.ENTER) {
                                    var recordIndex = rawItemFieldStore.findExact('name', combo.lastMutatedValue);
                                    if(recordIndex === -1) {
                                        if(combo.lastMutatedValue === '') {
                                            combo.ownerCt.completeEdit();
                                            if (this.queryById('saveButton').isHidden()) {
                                                store.sync();
                                            }
                                        } else {
                                            var record = combo.up('editor').context.record;

                                            var recordSettings = record.get('settings');
                                            if (!recordSettings.calculation) {
                                                recordSettings.calculation = 'return \'' + combo.lastMutatedValue + '\';';

                                                record.set('settings', recordSettings);
                                                record.dirty = true;
                                                if (this.queryById('saveButton').isHidden()) {
                                                    store.sync();
                                                }
                                            }
                                        }
                                    }
                                }
                            }.bind(this)
                        }
                    }
                },
                {
                    xtype: 'actioncolumn',
                    header: t('pim.transformations'),
                    dataIndex: 'settings',
                    width: 110,
                    menuDisabled: true,
                    tdCls: 'supportDnD',
                    stopSelection: false,
                    items: [{
                        tooltip: t('pim.dataport_configpanel'),
                        icon: "/bundles/opendxpadmin/img/flat-color-icons/tag.svg",
                        getClass: function (v, metadata, record) {
                            var settings = record.get('settings');
                            if (settings && settings.calculation && typeof settings.calculation == 'string' && settings.calculation.length > 0) {
                                metadata.css = 'pim_calculation';
                            }
                            return '';
                        },
                        handler: function (grid, rowIndex) {
                            var record = grid.getStore().getAt(rowIndex);
                            mappingPanel.showSettingsWindow(record);
                        }
                    }, {
                        icon: "/bundles/opendxpadmin/img/flat-color-icons/key.svg",
                        getClass: function (v, metadata, record) {
                            var settings = record.get('settings');
                            if (!settings || !settings.keyMapping) {
                                return 'x-hidden-display';
                            }

                            if(!settings.keyMappingIndexed) {
                                return 'mapping-is-key-field-without-index';
                            }

                            return '';
                        },
                        getTip: function (value, metadata, record, row, col, store) {
                            var settings = record.get('settings');
                            if (!settings.keyMappingIndexed) {
                                return t('pim.mapping.key_attribute_missing_index');
                            }

                            return t('pim.mapping.key_attribute');
                        },
                        handler: function (grid, rowIndex) {
                            var record = grid.getStore().getAt(rowIndex);
                            mappingPanel.showSettingsWindow(record);
                        }
                    }, {
                        icon: "/bundles/opendxpadmin/img/flat-color-icons/cancel.svg",
                        getClass: function (v, metadata, record) {
                            if (!record.get('hasIgnoredData')) {
                                return 'x-hidden-display';
                            }

                            return '';
                        },
                        tooltip: t('pim.mapping.ignored_values.tooltip'),
                        handler: function (grid, rowIndex) {
                            var record = store.getAt(rowIndex);

                            var ignoredValuesStore = Ext.create('Ext.data.JsonStore', {
                                fields: ['fullpath', 'value','classId','field'],
                                groupField: 'fullpath',
                                proxy: {
                                    type: 'ajax',
                                    api: {
                                        read: '/admin/SylphenDataBridge/import/get-ignored-values?field=' + encodeURIComponent(record.get('attributeKey'))+'&dataport=' + this.dataportId,
                                        destroy: '/admin/SylphenDataBridge/import/ignore'
                                    },
                                    reader: {
                                        type: 'json',
                                        rootProperty: 'ignoredValues',
                                        totalProperty: 'total',
                                        messageProperty: 'message',
                                        keepRawData: true
                                    },
                                    writer: {
                                        type: 'json',
                                        writeAllFields: true,
                                        allowSingle: false
                                    },
                                },

                                remoteFilter: true,
                                remoteSort: true,
                                autoSync: true,
                                autoLoad: true,
                                pageSize: 25
                            });

                            var toolbar = opendxp.helpers.grid.buildDefaultPagingToolbar(ignoredValuesStore, { pageSize: 25 });

                            var ignoredValuesPanel = Ext.create('Ext.grid.Panel', {
                                border: false,
                                frame: false,
                                columnLines: true,
                                stripeRows: true,
                                store: ignoredValuesStore,
                                bbar: toolbar,
                                columns: [
                                    {
                                        text: t('element'),
                                        dataIndex: 'fullpath',
                                        flex: 1,
                                        renderer: function (value, meta, record) {
                                            if (typeof value === "undefined") {
                                                return t('unknown');
                                            }
                                            return '<span style="cursor:pointer;text-decoration:underline">' + value + '</span>';
                                        }
                                    },
                                    { text: t('pim.mapping.ignored_values'), dataIndex: 'value', flex: 1 },
                                    {
                                        xtype: 'actioncolumn',
                                        width: 40,
                                        items: [{
                                            tooltip: t('pim.manual.importForm.cancel'),
                                            icon: "/bundles/opendxpadmin/img/flat-color-icons/cancel.svg",
                                            handler: function (grid, rowIndex) {
                                                var record = grid.getStore().getAt(rowIndex);
                                                ignoredValuesStore.remove(record);
                                            }.bind(this)
                                        }]
                                    }
                                ],
                                features: [
                                    Ext.create('Ext.grid.feature.Grouping', {
                                        groupHeaderTpl: '<i>{name}</i>',
                                        startCollapsed: true,
                                        enableGroupingMenu: false
                                    })
                                ],
                                plugins: [
                                    'gridfilters'
                                ],
                                viewConfig: {
                                    enableTextSelection: true
                                },
                                listeners: {
                                    cellclick: function (grid, tdElement, columnIndex, record) {
                                        var dataIndex = grid.getHeaderCt().getHeaderAtIndex(columnIndex).dataIndex;
                                        if (dataIndex === 'fullpath') {
                                            var elementType = 'object';
                                            if (record.get('classId') === 'asset') {
                                                elementType = 'asset';
                                            } else if (record.get('classId') === 'document') {
                                                elementType = 'document';
                                            }
                                            opendxp.helpers.openElement(record.get('fullpath'), elementType);
                                        }
                                    },
                                    itemcontextmenu: function (table, record, tr, rowIndex, e) {
                                        let field = record.get('field');

                                        var menu = new Ext.menu.Menu();

                                        menu.add({
                                            text: t('pim.manual.startimport.summary.dry-run.allow-all-values-for-field'),
                                            icon: "/bundles/opendxpadmin/img/flat-color-icons/approve.svg",
                                            handler: function () {
                                                Ext.MessageBox.confirm(t('pim.manual.startimport.summary.dry-run.allow-all-values-for-field'), t('pim.manual.startimport.summary.dry-run.allow-all-values-for-field.confirm'), function (btn) {
                                                    if (btn == 'yes' && field) {
                                                        function ignoreAllPages (store, page = 1) {
                                                            store.loadPage(page, {
                                                                callback: function (records, operation, success) {
                                                                    if (success && records.length > 0) {
                                                                        store.removeAll();

                                                                        // If the number of records is less than the pageSize, it's the last page
                                                                        if (records.length >= store.pageSize) {
                                                                            processAllPages(store, page + 1);
                                                                        }
                                                                    }
                                                                }
                                                            });
                                                        }

                                                        ignoreAllPages(ignoredValuesStore);
                                                    }
                                                });
                                            }
                                        });
                                        menu.showAt(e.pageX, e.pageY);
                                        e.stopEvent();
                                    }
                                }
                            });

                            new Ext.Window({
                                itemId: 'permissionWindow',
                                title: t('pim.mapping.ignored_values')+' ('+record.get('attributeKey')+')',
                                width: '80%',
                                height: '80%',
                                layout: 'fit',
                                items: [
                                    ignoredValuesPanel
                                ]
                            }).show();
                        }.bind(this)
                    }, {
                        getTip: function(value, metadata, record, row, col, store) {
                            var settings = record.get('settings');
                            if(!settings || !settings.format.translateFromLanguage) {
                                return '';
                            }
                            return t('pim.mapping.auto_translate')+' '+t(opendxp.available_languages[settings.format.translateFromLanguage]);
                        },
                        getClass: function (v, metadata, record) {
                            var settings = record.get('settings');
                            if (!settings || !settings.format.translateFromLanguage) {
                                return 'x-hidden-display';
                            }
                            return 'dd_icon_language_'+settings.format.translateFromLanguage;
                        },
                        handler: function (grid, rowIndex) {
                            var record = grid.getStore().getAt(rowIndex);
                            mappingPanel.showSettingsWindow(record);
                        }
                    }, {
                        tooltip: t('pim.mapping.auto_classification'),
                        getClass: function (v, metadata, record) {
                            var settings = record.get('settings');
                            if (!settings || !settings.format.auto_classification) {
                                return 'x-hidden-display';
                            }
                            return 'opendxp_icon_clear_cache';
                        },
                        handler: function (grid, rowIndex) {
                            var record = grid.getStore().getAt(rowIndex);
                            mappingPanel.showSettingsWindow(record);
                        }
                    }, {
                        tooltip: t('pim.mapping.auto_create_references'),
                        getClass: function (v, metadata, record) {
                            var settings = record.get('settings');
                            if (!settings || !settings.format.auto_create_references) {
                                return 'x-hidden-display';
                            }
                            return 'opendxp_icon_auto_generate_fields';
                        },
                        handler: function (grid, rowIndex) {
                            var record = grid.getStore().getAt(rowIndex);
                            mappingPanel.showSettingsWindow(record);
                        }
                    }, {
                        tooltip: t('pim.mapping.optimize.minimize'),
                        getClass: function (v, metadata, record) {
                            var settings = record.get('settings');
                            if (!settings || settings.format.optimize != '-1') {
                                return 'x-hidden-display';
                            }
                            return 'opendxp_icon_minimize';
                        },
                        handler: function (grid, rowIndex) {
                            var record = grid.getStore().getAt(rowIndex);
                            mappingPanel.showSettingsWindow(record);
                        }
                    }, {
                        tooltip: t('pim.mapping.optimize.maximize'),
                        getClass: function (v, metadata, record) {
                            var settings = record.get('settings');
                            if (!settings || settings.format.optimize != '1') {
                                return 'x-hidden-display';
                            }
                            return 'opendxp_icon_maximize';
                        },
                        handler: function (grid, rowIndex) {
                            var record = grid.getStore().getAt(rowIndex);
                            mappingPanel.showSettingsWindow(record);
                        }
                    }, {
                        tooltip: t('pim.mapping.truncate_before_import'),
                        getClass: function (v, metadata, record) {
                            var settings = record.get('settings');
                            if (!settings || !settings.format.purgeitems) {
                                return 'x-hidden-display';
                            }
                            return 'opendxp_icon_truncate';
                        },
                        handler: function (grid, rowIndex) {
                            var record = grid.getStore().getAt(rowIndex);
                            mappingPanel.showSettingsWindow(record);
                        }
                    }, {
                        tooltip: t('pim.mapping.delete_children_before_import'),
                        getClass: function (v, metadata, record) {
                            var settings = record.get('settings');
                            if (!settings || !settings.format.deleteChildren) {
                                return 'x-hidden-display';
                            }
                            return 'opendxp_icon_truncate';
                        },
                        handler: function (grid, rowIndex) {
                            var record = grid.getStore().getAt(rowIndex);
                            mappingPanel.showSettingsWindow(record);
                        }
                    }, {
                        tooltip: t('pim.mapping.write-protected'),
                        getClass: function (v, metadata, record) {
                            var settings = record.get('settings');
                            if (!settings || !settings.format.writeProtected) {
                                return 'x-hidden-display';
                            }
                            return 'opendxp_icon_lock';
                        },
                        handler: function (grid, rowIndex) {
                            var record = grid.getStore().getAt(rowIndex);
                            mappingPanel.showSettingsWindow(record);
                        }
                    }, {
                        tooltip: t('pim.mapping.overwrite_images'),
                        getClass: function (v, metadata, record) {
                            var settings = record.get('settings');
                            if (!settings || !settings.format.overwrite) {
                                return 'x-hidden-display';
                            }
                            return 'opendxp_icon_overwrite_images';
                        },
                        handler: function (grid, rowIndex) {
                            var record = grid.getStore().getAt(rowIndex);
                            mappingPanel.showSettingsWindow(record);
                        }
                    }, {
                        tooltip: t('pim.mapping.prevent_duplicates'),
                        getClass: function (v, metadata, record) {
                            var settings = record.get('settings');
                            if (!settings || !settings.format.preventDuplicates) {
                                return 'x-hidden-display';
                            }
                            return 'opendxp_icon_groupby';
                        },
                        handler: function (grid, rowIndex) {
                            var record = grid.getStore().getAt(rowIndex);
                            mappingPanel.showSettingsWindow(record);
                        }
                    }, {
                        tooltip: t('pim.mapping.classificationStore.auto_generate_fields'),
                        getClass: function (v, metadata, record) {
                            var settings = record.get('settings');
                            if (!settings || !settings.format.auto_generate_fields) {
                                return 'x-hidden-display';
                            }
                            return 'opendxp_icon_auto_generate_fields';
                        },
                        handler: function (grid, rowIndex) {
                            var record = grid.getStore().getAt(rowIndex);
                            mappingPanel.showSettingsWindow(record);
                        }
                    }, {
                        tooltip: t('pim.mapping.infer'),
                        getClass: function (v, metadata, record) {
                            var settings = record.get('settings');
                            if (!settings || !settings.format.infer) {
                                return 'x-hidden-display';
                            }
                            return 'opendxp_icon_clear_cache';
                        },
                        handler: function (grid, rowIndex) {
                            var record = grid.getStore().getAt(rowIndex);
                            mappingPanel.showSettingsWindow(record);
                        }
                    }, {
                        tooltip: t('pim.mapping.auto_create_units'),
                        getClass: function (v, metadata, record) {
                            var settings = record.get('settings');
                            if (!settings || !settings.format.autoCreateUnits) {
                                return 'x-hidden-display';
                            }
                            return 'opendxp_icon_autoCreateUnits';
                        },
                        handler: function (grid, rowIndex) {
                            var record = grid.getStore().getAt(rowIndex);
                            mappingPanel.showSettingsWindow(record);
                        }
                    }, {
                        tooltip: t('pim.mapping.auto_create_options'),
                        getClass: function (v, metadata, record) {
                            var settings = record.get('settings');
                            if (!settings || !settings.format.autoCreate) {
                                return 'x-hidden-display';
                            }
                            return 'opendxp_icon_multiselect';
                        },
                        handler: function (grid, rowIndex) {
                            var record = grid.getStore().getAt(rowIndex);
                            mappingPanel.showSettingsWindow(record);
                        }
                    }]
                },
                {
                    header: t('pim.mapping.example.raw'),
                    dataIndex: 'example',
                    flex: 1,
                    renderer: function (data, metaData, mappingRecord) {
                        var id = Ext.id();

                        function getChildren(jsonObj) {
                            var results = [];
                            if( jsonObj !== null && typeof jsonObj == "object" ) {
                                Object.entries(jsonObj).forEach(([key, value]) => {
                                    // key is either an array index or object key
                                    var children = getChildren(value);
                                    results.push({
                                        text: key+((children.length === 0)?': '+value:''),
                                        children: children,
                                        leaf: children.length < 1,
                                        expanded: key == 0
                                    });
                                });

                                return results;
                            }
                            else {
                                // jsonObj is a number or string
                                return [];
                            }
                        }

                        var children = [];
                        try {
                            if(data === null) {
                                data = '';
                            }
                            var json = JSON.parse(data);
                            if( json !== null && typeof json == "object" ) {
                                children = [{
                                    text: Array.isArray(json) || this.callbackLanguage === 'php' ? 'Array' : 'Object',
                                    expanded: true,
                                    children: getChildren(json),
                                    leaf: false
                                }];
                            } else {
                                children = [
                                    {
                                        text: data,
                                        leaf: true
                                    }
                                ];
                            }
                        } catch (e) {
                            if(data === '~~no-preview~~' && ['__result_callback', '__result_action'].indexOf(mappingRecord.get('attributeKey')) > -1) {
                                return '<i>'+t('pim.mapping.example.no-preview')+'</i>';
                            }
                            if (data === '~~export-hint~~' && ['__result_callback', '__result_action'].indexOf(mappingRecord.get('attributeKey')) > -1) {
                                return '<i>' + t('pim.mapping.example.export-hint') + '</i>';
                            }

                            return '<xmp style="font-family:inherit;display:inline">'+data+'</xmp>';
                        }

                        Ext.defer(function () {
                            if (Ext.get(id)) {
                                var store = Ext.create('Ext.data.TreeStore', {
                                    root: {
                                        expanded: true,
                                        children: children
                                    }
                                });

                                Ext.create('Ext.tree.Panel', {
                                    store: store,
                                    rootVisible: false,
                                    cls: 'no-icon',
                                    listeners: {
                                        itemclick: function(treePanel, record) {
                                            var columns = this.getColumns();
                                            var columnIndex = -1;
                                            for (index = 0; index < columns.length; ++index) {
                                                if (columns[index].dataIndex === 'example') {
                                                    columnIndex = index;
                                                    break;
                                                }
                                            }

                                            if(columnIndex !== -1) {
                                                this.editingPlugin.startEdit(mappingRecord, columnIndex);
                                            }
                                        }.bind(this),
                                        afterrender: function() {
                                            this.getEl().swallowEvent([
                                                'mousedown', 'mouseup', 'click',
                                                'contextmenu', 'mouseover', 'mouseout',
                                                'dblclick', 'mousemove', 'focus', 'focusenter'
                                            ]); // advice from here: http://hmxamughal.blog.com/2012/10/23/grid-in-grid/
                                        }
                                    },
                                    renderTo: id,
                                });
                            }
                        }.bind(this), 50);

                        return '<div id="'+id+'"></div>';
                    },
                    editor: alternativeRawItemEditor
                },
                {
                    header: t('pim.mapping.example.parsed'),
                    dataIndex: 'example_parsed',
                    flex: 1,
                    renderer: function(value, metaData, record) {
                        if(value.result === null) {
                            value.result = '';
                        }

                        var qtip = '';

                        metaData.tdCls = '';

                        var worstLogType = null;
                        if (value.logs.length > 0) {
                            value.logs = value.logs.map(function(log) { return Ext.util.Format.nl2br(log); });
                            qtip += '<ul><li>' + value.logs.join('</li><li>') + '</li></ul><hr>';

                            var possibleWorseErrorlevels = ['EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR', 'WARNING', 'NOTICE'];
                            Ext.each(value.logs, function (log) {
                                for(var logLevel in possibleWorseErrorlevels) {
                                    if (log.indexOf('['+possibleWorseErrorlevels[logLevel]+']') > -1) {
                                        metaData.tdCls = 'log-'+ possibleWorseErrorlevels[logLevel];
                                        possibleWorseErrorlevels = possibleWorseErrorlevels.slice(0, logLevel);
                                        return true;
                                    }
                                }
                            });
                        }

                        if (record.get('output')) {
                            qtip += t('pim.mapping.callback_function.unexpected_output')+': <xmp>' + record.get('output') + '</xmp><hr>';
                            metaData.tdCls = 'pim_calculation_warnings';
                        }

                        var settings = record.get('settings');
                        if (settings.calculation) {
                            if(settings.calculation.match(/\$params\[["']value["']\]/) && !record.get('field')) {
                                qtip += t('pim.mapping.callback_function.no_rawdata_field_assigned')+'<hr>';
                                metaData.tdCls = 'pim_calculation_warnings';
                            }
                        }

                        if (value.result) {
                            qtip += Ext.util.Format.htmlEncode(value.result.toString()) + '<hr>';
                        }

                        if (value.result) {
                            qtip += t('pim.mapping.preview_hint');
                        }

                        if(qtip) {
                            metaData.tdAttr = 'data-qtip=\'' + Ext.util.Format.htmlEncode(qtip) + '\'';
                        }

                        if (!value.valid) {
                            metaData.tdCls = 'pim_calculation_invalid';
                        }

                        if (value.result === '~~no-preview~~' && ['__result_callback', '__result_action'].indexOf(record.get('attributeKey')) > -1) {
                            return '<i>' + t('pim.mapping.example.no-preview') + '</i>';
                        }

                        if (value.result === '~~export-hint~~' && ['__result_callback', '__result_action'].indexOf(record.get('attributeKey')) > -1) {
                            return '<i>' + t('pim.mapping.example.export-hint') + '</i>';
                        }

                        return Ext.util.Format.htmlEncode(value.result.toString()).replace(/([^>\r\n]?)(\r\n|\n\r|\r|\n)/g, '$1<br>');
                    }
                },
                {
                    xtype: 'actioncolumn',
                    width: 60, // otherwise column gets hidden behind scrollbar on browsers with "dynamic" scrollbar
                    menuDisabled: true,
                    stopSelection: false,
                    hidden: !opendxp.globalmanager.get("user").isAllowed('plugin_sylphen_data_bridge_admin_permission') && !opendxp.globalmanager.get("user").isAllowed('Dataport ' + this.dataportId + ' Configuration'),
                    items: [{
                        tooltip: t('pim.mapping.remove_mapping'),
                        icon: "/bundles/opendxpadmin/img/flat-color-icons/delete.svg",
                        handler: function (grid, rowIndex) {
                            var record = store.getAt(rowIndex);

                            var setFields = {};
                            var settings = JSON.parse(JSON.stringify(record.get('settings'))); // clone
                            setFields.settings = settings;

                            if(record.get('field')) {
                                setFields.field = null;

                                if(settings.calculation) {
                                    Ext.MessageBox.confirm(t('delete'), t('pim.mapping.callback_function.confirm_delete'), function(btn){
                                        if(btn === 'yes') {
                                            setFields.settings.calculation = '';
                                        }
                                        record.set(setFields);

                                        if (this.queryById('saveButton').isHidden()) {
                                            store.sync();
                                        }
                                    }.bind(this));
                                } else {
                                    record.set(setFields);
                                    if (this.queryById('saveButton').isHidden()) {
                                        store.sync();
                                    }
                                }
                            } else {
                                setFields.settings.calculation = '';
                                record.set(setFields);
                                if (this.queryById('saveButton').isHidden()) {
                                    store.sync();
                                }
                            }
                        }.bind(this),
                        getClass: function (v, metadata, record) {
                            var settings = JSON.parse(JSON.stringify(record.get('settings')));
                            if (!settings.calculation && !record.get('field')) {
                                return 'x-hidden-display';
                            }
                            return '';
                        },
                    }]
                }
            ]
        });

        opendxp.plugin.Pim.MappingPanel.superclass.initComponent.call(this);
    },

    showSettingsWindow: function (record) {
        var settings = record.get('settings'),
            format = settings.format;

        var fields = [];

        if (typeof record.data.description != 'undefined') {
            fields.push({
                xtype: 'component',
                html: record.get('description')
            });
        }

        if(['__result_callback', '__result_action', '__init_action'].indexOf(record.get('attributeKey')) === -1 && record.get('attributeKey').indexOf('__virtual_') === -1 && ['block', 'fieldcollections', 'classificationstore'].indexOf(record.get('type')) === -1) {
            fields.push({
                xtype: 'checkbox',
                name: 'keyMapping',
                boxLabel: t('pim.mapping.key_attribute'),
                checked: settings.keyMapping
            });
        }

        if(record.get('type') !== 'calculatedValue' && ['id','path','key','filename','published'].indexOf(record.get('attributeKey').toLowerCase()) < 0) {
            fields.push({
                xtype: 'checkbox',
                name: 'writeProtected',
                boxLabel: t('pim.mapping.write-protected'),
                checked: format.writeProtected
            });
        }

        if(record.get('attributeKey').toLowerCase() === 'path') {
            fields.push({
                xtype: 'checkbox',
                name: 'deleteChildren',
                boxLabel: t('pim.mapping.delete_children_before_import'),
                checked: format.deleteChildren
            });
        }

        switch (record.get('type')) {
            case 'input':
                var deeplApiKey = Ext.create('Ext.form.field.Text', {
                    name: 'deeplApiKey',
                    fieldLabel: t('pim.mapping.deeplApiKey'),
                    hidden: !format.translateFromLanguage,
                    value: format.deeplApiKey || ''
                });

                var translateFromLanguage = Ext.create('Ext.form.field.ComboBox', {
                    xtype: 'combo',
                    name: 'translateFromLanguage',
                    fieldLabel: t('pim.mapping.auto_translate'),
                    forceSelection: true,
                    editable: false,
                    store: this.languageStore,
                    value: format.translateFromLanguage || null,
                    valueField: 'value',
                    hidden: !!format.infer, // format.infer can be an array for deleted advanced m2m relations
                    listConfig: {
                        tpl: [
                            '<tpl for=".">',
                            '<div role="option" class="x-boundlist-item"><div style="height:21px;display:inline-block;padding-left:34px;background-position: 0 0" class="dd_icon_language_{value}">',
                            '{[values.defaultLanguage ? "<b>"+values.text+"</b>" : values.text]}',
                            '</div></div>',
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
                        },
                        change: function (field, value) {
                            if (value) {
                                deeplApiKey.show();
                                inferCheckbox.setValue(false);
                                inferCheckbox.hide();
                            } else {
                                deeplApiKey.hide();
                                inferCheckbox.show();
                            }
                        },
                        hide: function () {
                            deeplApiKey.hide();
                        },
                        show: function () {
                            if (translateFromLanguage.getValue()) {
                                deeplApiKey.show();
                            }
                        }
                    }
                });

                fields.push(translateFromLanguage);
                fields.push(deeplApiKey);

                var openAiKey = Ext.create('Ext.form.field.Text', {
                    name: 'openAiKey',
                    fieldLabel: t('pim.mapping.openAiKey'),
                    hidden: !format.infer,
                    value: format.openAiKey || ''
                });

                var inferCheckbox = Ext.create('Ext.form.field.Checkbox', {
                    name: 'infer',
                    boxLabel: t('pim.mapping.infer'),
                    checked: !!format.infer, // format.infer can be an array for deleted advanced m2m relations
                    hidden: !!format.translateFromLanguage,
                    autoEl: {
                        tag: 'div',
                        'data-qtip': t('pim.mapping.infer.tooltip')
                    },
                    listeners: {
                        change: function (field, value) {
                            if (value) {
                                openAiKey.show();
                                translateFromLanguage.setValue('');
                                translateFromLanguage.hide();
                            } else {
                                openAiKey.hide();
                                translateFromLanguage.show();
                            }
                        },
                        hide: function () {
                            openAiKey.hide();
                        },
                        show: function () {
                            if (inferCheckbox.getValue()) {
                                openAiKey.show();
                            }
                        }
                    }
                });

                fields.push(inferCheckbox);
                fields.push(openAiKey);

                break;
            case 'textarea':
            case 'wysiwyg':
                var deeplApiKey = Ext.create('Ext.form.field.Text', {
                    name: 'deeplApiKey',
                    fieldLabel: t('pim.mapping.deeplApiKey'),
                    hidden: !format.translateFromLanguage,
                    value: format.deeplApiKey
                });

                var translateFromLanguage = Ext.create('Ext.form.field.ComboBox', {
                    xtype: 'combo',
                    name: 'translateFromLanguage',
                    fieldLabel: t('pim.mapping.auto_translate'),
                    forceSelection: true,
                    editable: false,
                    store: this.languageStore,
                    value: format.translateFromLanguage,
                    valueField: 'value',
                    hidden: format.generateText,
                    listConfig: {
                        tpl: [
                            '<tpl for=".">',
                            '<div role="option" class="x-boundlist-item"><div style="height:21px;display:inline-block;padding-left:34px;background-position: 0 0" class="dd_icon_language_{value}">',
                            '{[values.defaultLanguage ? "<b>"+values.text+"</b>" : values.text]}',
                            '</div></div>',
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
                        },
                        change: function (field, value) {
                            if (value) {
                                deeplApiKey.show();
                                generateTextCheckbox.setValue(false);
                                generateTextCheckbox.hide();
                            } else {
                                deeplApiKey.hide();
                                generateTextCheckbox.show();
                            }
                        },
                        hide: function() {
                            deeplApiKey.hide();
                        },
                        show: function () {
                            if (translateFromLanguage.getValue()) {
                                deeplApiKey.show();
                            }
                        }
                    }
                });

                fields.push(translateFromLanguage);
                fields.push(deeplApiKey);

                var openAiKey = Ext.create('Ext.form.field.Text', {
                    name: 'openAiKey',
                    fieldLabel: t('pim.mapping.openAiKey'),
                    value: format.openAiKey,
                    flex: 1,
                    labelWidth: 150
                });

                var generateTextCheckbox = Ext.create('Ext.form.field.Checkbox', {
                    name: 'generateText',
                    boxLabel: t('pim.mapping.auto_generate'),
                    checked: format.generateText,
                    hidden: !!format.translateFromLanguage,
                    autoEl: {
                        tag: 'div',
                        'data-qtip': t('pim.mapping.auto_generate.tooltip')
                    },
                    listeners: {
                        change: function (field, value) {
                            if (value) {
                                textGenerateContainer.show();
                                translateFromLanguage.setValue('');
                                translateFromLanguage.hide();
                            } else {
                                textGenerateContainer.hide();
                                translateFromLanguage.show();
                            }
                        },
                        hide: function() {
                            textGenerateContainer.hide();
                        },
                        show: function() {
                            if(generateTextCheckbox.getValue()) {
                                textGenerateContainer.show();
                            }
                        }
                    }
                });

                var textGenerateContainer = Ext.create('Ext.form.FieldContainer', {
                    hidden: !format.generateText,
                    layout: {
                        type: 'hbox',
                        align: 'stretch'
                    },
                    items: [
                        openAiKey,
                        Ext.create('Ext.form.field.ComboBox', {
                            name: 'textLength',
                            fieldLabel: t('pim.mapping.auto_generate.textLength'),
                            value: format.textLength || 1,
                            forceSelection: true,
                            editable: false,
                            labelStyle: 'padding-left: 10px',
                            autoEl: {
                                tag: 'div',
                                'data-qtip': t('pim.mapping.auto_generate.textLength.tooltip')
                            },
                            store: [
                                [ "1", t('pim.mapping.auto_generate.textLength.short') ],
                                [ "2", t('pim.mapping.auto_generate.textLength.medium') ],
                                [ "3", t('pim.mapping.auto_generate.textLength.long') ]
                            ],
                            flex: 1
                        })
                    ]
                });

                fields.push(generateTextCheckbox);
                fields.push(textGenerateContainer);
                break;
            case 'numeric':
                fields.push({
                    xtype: 'textfield',
                    name: 'groupingSeparator',
                    boxLabel: t('pim.mapping.thousands_separator'),
                    value: format.groupingSeparator,
                    hidden: true
                }, {
                    xtype: 'textfield',
                    name: 'decimalSeparator',
                    fieldLabel: t('pim.mapping.decimal_separator'),
                    value: format.decimalSeparator,
                    hidden: true
                });

                var openAiKey = Ext.create('Ext.form.field.Text', {
                    name: 'openAiKey',
                    fieldLabel: t('pim.mapping.openAiKey'),
                    hidden: !format.infer,
                    value: format.openAiKey
                });

                var inferCheckbox = Ext.create('Ext.form.field.Checkbox', {
                    name: 'infer',
                    boxLabel: t('pim.mapping.infer'),
                    checked: format.infer,
                    hidden: !record.get('brickName'),
                    autoEl: {
                        tag: 'div',
                        'data-qtip': t('pim.mapping.infer.tooltip')
                    },
                    listeners: {
                        change: function (field, value) {
                            if (value) {
                                openAiKey.show();
                            } else {
                                openAiKey.hide();
                            }
                        },
                        hide: function () {
                            openAiKey.hide();
                        },
                        show: function () {
                            if (inferCheckbox.getValue()) {
                                openAiKey.show();
                            }
                        }
                    }
                });

                fields.push(inferCheckbox);
                fields.push(openAiKey);

                break;

            case 'quantityValue':
            case 'inputQuantityValue':
                fields.push({
                    xtype: 'checkbox',
                    name: 'autoCreateUnits',
                    boxLabel: t('pim.mapping.auto_create_units'),
                    checked: format.autoCreateUnits
                });
                break;

            case 'date':
            case 'datetime':
                fields.push({
                    xtype: 'textfield',
                    name: 'dateFormat',
                    fieldLabel: t('date_format'),
                    value: format.dateFormat
                });

                break;
            case 'select':
                fields.push({
                    xtype: 'checkbox',
                    name: 'autoCreate',
                    boxLabel: t('pim.mapping.auto_create_options'),
                    checked: format.autoCreate
                });

                var openAiKey = Ext.create('Ext.form.field.Text', {
                    name: 'openAiKey',
                    fieldLabel: t('pim.mapping.openAiKey'),
                    hidden: !format.infer,
                    value: format.openAiKey
                });

                var inferCheckbox = Ext.create('Ext.form.field.Checkbox', {
                    name: 'infer',
                    boxLabel: t('pim.mapping.infer'),
                    checked: format.infer,
                    hidden: !record.get('brickName'),
                    autoEl: {
                        tag: 'div',
                        'data-qtip': t('pim.mapping.infer.tooltip')
                    },
                    listeners: {
                        change: function (field, value) {
                            if (value) {
                                openAiKey.show();
                            } else {
                                openAiKey.hide();
                            }
                        },
                        hide: function () {
                            openAiKey.hide();
                        },
                        show: function () {
                            if (inferCheckbox.getValue()) {
                                openAiKey.show();
                            }
                        }
                    }
                });

                fields.push(inferCheckbox);
                fields.push(openAiKey);

                break;
            case 'multiselect':
            case 'countrymultiselect':
                fields.push({
                    xtype: 'checkbox',
                    name: 'purgeitems',
                    boxLabel: t('pim.mapping.truncate_before_import'),
                    checked: format.purgeitems
                });
                fields.push({
                    xtype: 'textfield',
                    name: 'separator',
                    fieldLabel: t('delimiter'),
                    value: format.separator
                });

                if(record.get('type') === 'multiselect') {
                    fields.push({
                        xtype: 'checkbox',
                        name: 'autoCreate',
                        boxLabel: t('pim.mapping.auto_create_options'),
                        checked: format.autoCreate
                    });
                }

                var openAiKey = Ext.create('Ext.form.field.Text', {
                    name: 'openAiKey',
                    fieldLabel: t('pim.mapping.openAiKey'),
                    hidden: !format.infer,
                    value: format.openAiKey
                });

                var inferCheckbox = Ext.create('Ext.form.field.Checkbox', {
                    name: 'infer',
                    boxLabel: t('pim.mapping.infer'),
                    checked: format.infer,
                    hidden: !record.get('brickName'),
                    autoEl: {
                        tag: 'div',
                        'data-qtip': t('pim.mapping.infer.tooltip')
                    },
                    listeners: {
                        change: function (field, value) {
                            if (value) {
                                openAiKey.show();
                            } else {
                                openAiKey.hide();
                            }
                        },
                        hide: function () {
                            openAiKey.hide();
                        },
                        show: function () {
                            if (inferCheckbox.getValue()) {
                                openAiKey.show();
                            }
                        }
                    }
                });

                fields.push(inferCheckbox);
                fields.push(openAiKey);

                break;

            case 'image':
                fields.push({
                    xtype: 'checkbox',
                    name: 'overwrite',
                    boxLabel: t('pim.mapping.overwrite_images'),
                    checked: format.overwrite
                });

                fields.push({
                    xtype: 'checkbox',
                    name: 'preventDuplicates',
                    boxLabel: t('pim.mapping.prevent_duplicates'),
                    checked: format.preventDuplicates
                });

                break;
            case 'imageGallery':
                fields.push({
                    xtype: 'checkbox',
                    name: 'purgeitems',
                    boxLabel: t('pim.mapping.truncate_before_import'),
                    checked: format.purgeitems
                });
                fields.push({
                    xtype: 'checkbox',
                    name: 'overwrite',
                    boxLabel: t('pim.mapping.overwrite_images'),
                    checked: format.overwrite
                });

                fields.push({
                    xtype: 'checkbox',
                    name: 'preventDuplicates',
                    boxLabel: t('pim.mapping.prevent_duplicates'),
                    checked: format.preventDuplicates
                });

                break;

            case 'hotspotimage':
                fields.push({
                    xtype: 'checkbox',
                    name: 'overwrite',
                    boxLabel: t('pim.mapping.overwrite_images'),
                    checked: format.overwrite
                });

                fields.push({
                    xtype: 'checkbox',
                    name: 'preventDuplicates',
                    boxLabel: t('pim.mapping.prevent_duplicates'),
                    checked: format.preventDuplicates
                });

                break;

            case 'multihref':
            case 'manyToManyRelation':
            case 'advancedManyToManyRelation':
            case 'multihrefMetadata':
                fields.push({
                    xtype: 'checkbox',
                    name: 'overwrite',
                    boxLabel: t('pim.mapping.overwrite_images'),
                    checked: format.overwrite
                });
                fields.push({
                    xtype: 'checkbox',
                    name: 'purgeitems',
                    boxLabel: t('pim.mapping.truncate_before_import'),
                    checked: format.purgeitems
                });

                fields.push({
                    xtype: 'checkbox',
                    name: 'preventDuplicates',
                    boxLabel: t('pim.mapping.prevent_duplicates'),
                    checked: format.preventDuplicates
                });

                break;


            case 'advancedManyToManyObjectRelation':
            case 'objectsMetadata':
                fields.push({
                    xtype: 'checkbox',
                    name: 'purgeitems',
                    boxLabel: t('pim.mapping.truncate_before_import'),
                    checked: format.purgeitems
                });

                var openAiKey = Ext.create('Ext.form.field.Text', {
                    name: 'openAiKey',
                    fieldLabel: t('pim.mapping.openAiKey'),
                    hidden: !format.infer,
                    value: format.openAiKey
                });

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


                var inferColumnsSelectField = new Overridden.form.field.Tag({
                    name: 'infer',
                    store: Ext.create('Ext.data.JsonStore', {
                        proxy: {
                            type: 'ajax',
                            url: '/admin/SylphenDataBridge/mappingconfig/get-meta-columns',
                            extraParams: {
                                targetClass: this.dataportPanel.dataportPanel.getForm().findField('itemClass').getValue(),
                                attributeKey: record.get('attributeKey'),
                                locale: record.get('locale'),
                                brickName: record.get('brickName'),
                                targetBrickField: record.get('targetBrickField')
                            },
                            reader: {
                                type: 'json',
                                rootProperty: 'columns'
                            }
                        },
                        fields: ['key', 'label'],
                        autoLoad: true
                    }),
                    displayField: 'label',
                    valueField: 'key',
                    fieldLabel: t('pim.mapping.infer.advanced-relations'),
                    value: (format.infer || '').split(',').filter(function (field) {
                        return field !== '';
                    }),
                    editable: true,
                    selectOnFocus: true,
                    queryMode: 'local',
                    filterPickList: true,
                    typeAhead: true,
                    anyMatch: true,
                    minChars: 1,
                    forceSelection: true,
                    autoEl: {
                        tag: 'div',
                        'data-qtip': t('pim.mapping.infer.tooltip')
                    },
                    listeners: {
                        change: function (field, value) {
                            if (value.length > 0) {
                                openAiKey.show();
                            } else {
                                openAiKey.hide();
                            }
                        }
                    }
                });

                fields.push(inferColumnsSelectField);
                fields.push(openAiKey);
                break;

            case 'fieldcollections':
            case 'block':
                fields.push({
                    xtype: 'checkbox',
                    name: 'purgeitems',
                    boxLabel: t('pim.mapping.truncate_before_import'),
                    checked: format.purgeitems
                });

                fields.push({
                    xtype: 'checkbox',
                    name: 'autoCreate',
                    boxLabel: t('pim.mapping.block.auto_create'),
                    checked: format.autoCreate
                });

                break;

            case 'manyToManyObjectRelation':
            case 'objects':
                fields.push({
                    xtype: 'checkbox',
                    name: 'purgeitems',
                    boxLabel: t('pim.mapping.truncate_before_import'),
                    checked: format.purgeitems
                });

                fields.push({
                    xtype: 'checkbox',
                    name: 'auto_classification',
                    boxLabel: t('pim.mapping.auto_classification'),
                    checked: format.auto_classification,
                    hidden: true
                });

                fields.push({
                    xtype: 'checkbox',
                    name: 'auto_create_references',
                    boxLabel: t('pim.mapping.auto_create_references'),
                    checked: format.auto_create_references
                });
                break;

            case 'manyToOneRelation':
                fields.push({
                    xtype: 'checkbox',
                    name: 'auto_create_references',
                    boxLabel: t('pim.mapping.auto_create_references'),
                    checked: format.auto_create_references
                });
                break;

            case 'genericObjectRelation':
                fields.push({
                    xtype: 'checkbox',
                    name: 'purgeitems',
                    boxLabel: t('pim.mapping.truncate_before_import'),
                    checked: format.purgeitems
                });
                break;

            case 'calculatedValue':
                if (['__result_callback', '__result_action', '__init_action'].indexOf(record.get('attributeKey')) === -1 && record.get('attributeKey').indexOf('__virtual_') === -1) {
                    fields.push({
                        xtype: 'combo',
                        store: Ext.create('Ext.data.Store', {
                            fields: ['factor', 'name'],
                            data: [
                                { "factor": "", "name": t('pim.mapping.optimize.none') },
                                { "factor": "-1", "name": t('pim.mapping.optimize.minimize') },
                                { "factor": "1", "name": t('pim.mapping.optimize.maximize') }
                            ]
                        }),
                        name: 'optimize',
                        fieldLabel: t('pim.mapping.optimize'),
                        value: format.optimize,
                        displayField: 'name',
                        valueField: 'factor',
                        forceSelection: true,
                        editable: false,
                        autoEl: {
                            tag: 'div',
                            'data-qtip': t('pim.mapping.optimize.tooltip')
                        },
                    });
                }
                break;
            case 'objectbricks':
                fields.push({
                    xtype: 'checkbox',
                    name: 'auto_generate_fields',
                    boxLabel: t('pim.mapping.classificationStore.auto_generate_fields'),
                    checked: format.auto_generate_fields
                });

                fields.push({
                    xtype: 'checkbox',
                    name: 'purgeitems',
                    boxLabel: t('pim.mapping.truncate_before_import.objectbricks'),
                    checked: format.purgeitems
                });
                break;

            case 'classificationstore':
                fields.push({
                    xtype: 'checkbox',
                    name: 'auto_generate_fields',
                    boxLabel: t('pim.mapping.classificationStore.auto_generate_fields'),
                    checked: format.auto_generate_fields
                });
        }

        var templates = record.get('templates');
        var highlightingLanguage = this.callbackLanguage;
        var callbackFunctionEditor;
        if (highlightingLanguage === 'v8Js' || highlightingLanguage === 'spidermonkey') {
            highlightingLanguage = 'js';
        }
        if(templates.length > 0) {
            fields.push({
                xtype: 'combo',
                fieldLabel: t('template'),
                forceSelection: true,
                anyMatch: true,
                store: Ext.create('Ext.data.Store', {
                    fields: ['key', 'value', 'group'],
                    data : templates
                }),
                listConfig: {
                    tpl: [
                        '<tpl for=".">',
                        '{[typeof values.group !== "undefined" && (xindex === 1 || parent[xindex - 2].group !== values.group) ? "<div style=\'padding: 5px 10px\'>"+values.group+"</div>" : ""]}',
                        '<div role="option" class="x-boundlist-item">{[typeof values.group !== "undefined" ? "&nbsp;&nbsp;" : ""]}{key}</div>',
                        '</tpl>'
                    ]
                },
                value: settings.calculation,
                queryMode: 'local',
                displayField: 'key',
                valueField: 'value',
                listeners: {
                    focus: function (combo) {
                        setTimeout(function() {
                            if (!combo.isExpanded) {
                                combo.expand();
                            }
                        }, 100);
                    },
                    'select': function (combo, record) {
                        if (callbackFunctionEditor.getValue() !== '' && callbackFunctionEditor.getValue() !== t('pim.mapping.callback_function.code_placeholder')) {
                            Ext.MessageBox.confirm(t('delete'), t('pim.mapping.callback_function.confirm_delete'), function (btn) {
                                if (btn === 'yes') {
                                    callbackFunctionEditor.setValue(record.get('value'));
                                }
                            }.bind(this));
                        } else {
                            callbackFunctionEditor.setValue(record.get('value'));
                        }
                    }.bind(this)
                }
            });
        }

        var editorId = 'callback_function_' + this.dataportId+'_'+ record.get('attributeName')+'_'+ Ext.id();
        var editorContainer = new Ext.Component({
            html: '<div id="' + editorId + '" style="height:100%;width:100%"></div>',
            listeners: {
                afterrender: function (cmp) {
                    var editor = ace.edit(editorId);
                    editor.setTheme('ace/theme/chrome');

                    if(highlightingLanguage === 'php') {
                        editor.session.setMode({ path: "ace/mode/php", inline: true });
                    } else {
                        editor.session.setMode('ace/mode/javascript');
                    }

                    var languageTools = ace.require("ace/ext/language_tools");
                    editor.completers = [languageTools.snippetCompleter, languageTools.textCompleter, {
                        getCompletions: function (editor, session, pos, prefix, callback) {
                            if (['__result_callback', '__result_action', '__init_action'].indexOf(record.get('attributeKey')) === -1) {
                                // $params['value']
                                var preview;
                                try {
                                    preview = JSON.stringify(JSON.parse(record.get('rawValue')));
                                } catch (e) {
                                    preview = record.get('rawValue');
                                }

                                var variables = [
                                    { caption: (this.callbackLanguage === 'php' ? '$params[\'value\']' : 'params.value') + ': ' + preview, value: this.callbackLanguage === 'php' ? '$params[\'value\']' : 'params.value', score: preview === 'null' ? 1 : 4, meta: "" },
                                ];

                                // $params['rawItemData']
                                try {
                                    var rawItemData = record.get('rawItemData');

                                    if (Object.keys(rawItemData).length > 0) {
                                        for (var field in rawItemData) {
                                            try {
                                                preview = JSON.stringify(rawItemData[field].value);
                                            } catch (e) {
                                                preview = rawItemData[field].value;
                                            }

                                            variables.push({
                                                caption: (this.callbackLanguage === 'php' ? '$params[\'rawItemData\'][\'' + field + '\'][\'value\']' : 'params.rawItemData[\'' + field + '\'].value') + ': ' + preview,
                                                value: this.callbackLanguage === 'php' ? '$params[\'rawItemData\'][\'' + field + '\'][\'value\']' : 'params.rawItemData[\'' + field + '\'].value',
                                                score: 3,
                                                meta: ""
                                            });
                                        }
                                    } else {
                                        throw new Error('Raw data empty -> falling back to list $params[\'rawItemData\'] as variable');
                                    }
                                } catch (e) {
                                    variables.push({ caption: (this.callbackLanguage === 'php' ? '$params[\'rawItemData\']' : 'params.rawItemData'), value: this.callbackLanguage === 'php' ? '$params[\'rawItemData\']' : 'params.rawItemData', score: 3, meta: "" });
                                }

                                if (this.dataportPanel.dataportPanel.getForm().findField('itemClass').getValue() !== '0') {
                                    // $params['currentValue']
                                    try {
                                        preview = JSON.stringify(JSON.parse(record.get('currentValue')));
                                    } catch (e) {
                                        preview = record.get('currentValue');
                                    }

                                    variables.push({ caption: (this.callbackLanguage === 'php' ? '$params[\'currentValue\']' : 'params.currentValue') + ': ' + preview, value: this.callbackLanguage === 'php' ? '$params[\'currentValue\']' : 'params.currentValue', score: 2, meta: "" });

                                    // $params['currentObjectData']
                                    try {
                                        var currentObjectData = JSON.parse(record.get('currentObjectData'));

                                        if (Object.keys(currentObjectData).length > 0) {
                                            for (var field in currentObjectData) {
                                                try {
                                                    preview = JSON.stringify(currentObjectData[field]);
                                                } catch (e) {
                                                    preview = currentObjectData[field];
                                                }
                                                variables.push({
                                                    caption: (this.callbackLanguage === 'php' ? '$params[\'currentObjectData\'][\'' + field + '\']' : 'params.currentObjectData[\'' + field + '\']') + ': ' + preview,
                                                    value: this.callbackLanguage === 'php' ? '$params[\'currentObjectData\'][\'' + field + '\']' : 'params.currentObjectData[\'' + field + '\']',
                                                    score: 1,
                                                    meta: ""
                                                });
                                            }
                                        } else {
                                            throw new Error('Raw data empty -> falling back to list $params[\'currentObjectData\'] as variable');
                                        }
                                    } catch (e) {
                                        variables.push({ caption: (this.callbackLanguage === 'php' ? '$params[\'currentObjectData\']' : 'params.currentObjectData'), value: this.callbackLanguage === 'php' ? '$params[\'currentObjectData\']' : 'params.currentObjectData', score: 2, meta: "" });
                                    }

                                    // $params['keyValues']
                                    try {
                                        var keyValues = JSON.parse(record.get('keyValues'));

                                        for (var field in keyValues) {
                                            try {
                                                preview = JSON.stringify(keyValues[field]);
                                            } catch (e) {
                                                preview = keyValues[field];
                                            }
                                            variables.push({
                                                caption: (this.callbackLanguage === 'php' ? '$params[\'keyValues\'][\'' + field + '\']' : 'params.keyValues[\'' + field + '\']') + ': ' + preview,
                                                value: this.callbackLanguage === 'php' ? '$params[\'keyValues\'][\'' + field + '\']' : 'params.keyValues[\'' + field + '\']',
                                                score: 1,
                                                meta: ""
                                            });
                                        }
                                    } catch (e) {
                                        try {
                                            var keyValuesFromIteration = record.get('keyValues').split('\n').join('').match(/Iteration 1:\s*(\{.+?\})\s*Iteration 2/);
                                            if(keyValuesFromIteration === null) {
                                                throw e;
                                            }

                                            var keyValues = JSON.parse(keyValuesFromIteration[1]);
                                            for (var field in keyValues) {
                                                try {
                                                    preview = JSON.stringify(keyValues[field]);
                                                } catch (e) {
                                                    preview = keyValues[field];
                                                }
                                                variables.push({
                                                    caption: (this.callbackLanguage === 'php' ? '$params[\'keyValues\'][\'' + field + '\']' : 'params.keyValues[\'' + field + '\']') + ': ' + preview,
                                                    value: this.callbackLanguage === 'php' ? '$params[\'keyValues\'][\'' + field + '\']' : 'params.keyValues[\'' + field + '\']',
                                                    score: 1,
                                                    meta: ""
                                                });
                                            }
                                        } catch(ex) {
                                            variables.push({
                                                caption: (this.callbackLanguage === 'php' ? '$params[\'keyValues\']' : 'params.keyValues'),
                                                value: this.callbackLanguage === 'php' ? '$params[\'keyValues\']' : 'params.keyValues',
                                                score: 1,
                                                meta: ""
                                            });
                                        }
                                    }

                                    // $params['field'], $params['locale']
                                    var nameParts = record.get('attributeKey').split('#');
                                    variables.push({ caption: (this.callbackLanguage === 'php' ? '$params[\'field\']' : 'params.field')+': '+nameParts[0], value: this.callbackLanguage === 'php' ? '$params[\'field\']' : 'params.field', score: 1, meta: "" });
                                    if(nameParts.length === 2) {
                                        variables.push({ caption: (this.callbackLanguage === 'php' ? '$params[\'locale\']' : 'params.locale') + ': ' + nameParts[1], value: this.callbackLanguage === 'php' ? '$params[\'locale\']' : 'params.locale', score: 1, meta: "" });
                                    }
                                }

                                variables.push({ caption: '$params[\'logger\']: ' + t('pim.mapping.variables.logger'), value: '$params[\'logger\']' });
                                variables.push({ caption: '$params[\'context\'][\'dataport\'][\'id\']: ' + this.dataportId, value: '$params[\'context\'][\'dataport\'][\'id\']' });
                                variables.push({ caption: '$params[\'context\'][\'dataport\'][\'name\']: ' + this.dataportPanel.dataport.name, value: '$params[\'context\'][\'dataport\'][\'name\']' });
                                variables.push({ caption: '$params[\'context\'][\'user\'][\'id\']: ' + opendxp.globalmanager.get("user").id, value: '$params[\'context\'][\'user\'][\'id\']' });
                                variables.push({ caption: '$params[\'context\'][\'user\'][\'username\']: ' + opendxp.globalmanager.get("user").name, value: '$params[\'context\'][\'user\'][\'username\']' });
                                variables.push({ caption: '$params[\'transfer\']: ' + t('pim.mapping.variables.transfer'), value: '$params[\'transfer\']' });
                                variables.push({ caption: '$params[\'translator\']: ' + t('pim.mapping.variables.translator'), value: '$params[\'translator\']' });
                            } else {
                                var variables = [];
                                if(this.callbackLanguage === 'php') {
                                    variables.push({ caption: '$params[\'response\']', value: '$params[\'response\']' });
                                }

                                if (record.get('attributeKey') !== '__result_action' && record.get('attributeKey') !== '__init_action') {
                                    // $params['rawItemData']
                                    try {
                                        var rawItemData = JSON.parse(record.get('rawItemData'));

                                        if(Object.keys(rawItemData).length > 0) {
                                            for (var field in rawItemData) {
                                                try {
                                                    preview = JSON.stringify(rawItemData[field].value);
                                                } catch (e) {
                                                    preview = rawItemData[field].value;
                                                }
                                                variables.push({
                                                    caption: (this.callbackLanguage === 'php' ? '$params[\'rawItemData\'][\'' + field + '\'][\'value\']' : 'params.rawItemData[\'' + field + '\'].value') + ': ' + preview,
                                                    value: this.callbackLanguage === 'php' ? '$params[\'rawItemData\'][\'' + field + '\'][\'value\']' : 'params.rawItemData[\'' + field + '\'].value',
                                                    score: 1,
                                                    meta: ""
                                                });
                                            }
                                        } else {
                                            variables.push({ caption: (this.callbackLanguage === 'php' ? '$params[\'rawItemData\']' : 'params.rawItemData'), value: this.callbackLanguage === 'php' ? '$params[\'rawItemData\']' : 'params.rawItemData', score: 3, meta: "" });
                                        }
                                    } catch (e) {
                                        variables.push({ caption: (this.callbackLanguage === 'php' ? '$params[\'rawItemData\']' : 'params.rawItemData'), value: this.callbackLanguage === 'php' ? '$params[\'rawItemData\']' : 'params.rawItemData', score: 3, meta: "" });
                                    }
                                }

                                if (this.callbackLanguage === 'php') {
                                    variables.push({ caption: '$params[\'request\']', value: '$params[\'request\']' });
                                    variables.push({ caption: '$params[\'transfer\']: ' + t('pim.mapping.variables.transfer'), value: '$params[\'transfer\']' });
                                    variables.push({ caption: '$params[\'context\'][\'dataport\'][\'id\']: ' + this.dataportId, value: '$params[\'context\'][\'dataport\'][\'id\']' });
                                    variables.push({ caption: '$params[\'context\'][\'dataport\'][\'name\']: ' + this.dataportPanel.dataport.name, value: '$params[\'context\'][\'dataport\'][\'name\']' });
                                    variables.push({ caption: '$params[\'context\'][\'user\'][\'id\']: ' + opendxp.globalmanager.get("user").id, value: '$params[\'context\'][\'user\'][\'id\']' });
                                    variables.push({ caption: '$params[\'context\'][\'user\'][\'username\']: ' + opendxp.globalmanager.get("user").name, value: '$params[\'context\'][\'user\'][\'username\']' });
                                    variables.push({ caption: '$params[\'logger\']: ' + t('pim.mapping.variables.logger'), value: '$params[\'logger\']' });
                                    variables.push({ caption: '$params[\'translator\']: ' + t('pim.mapping.variables.translator'), value: '$params[\'translator\']' });
                                }

                                variables.push({ caption: (this.callbackLanguage === 'php' ? '$params[\'lastCall\']' : 'params.lastCall'), value: (this.callbackLanguage === 'php' ? '$params[\'lastCall\']' : 'params.lastCall') });

                            }

                            callback(null, variables);
                        }.bind(this)
                    }];
                    editor.setOptions({
                        showLineNumbers: true,
                        showPrintMargin: false,
                        wrap: true,
                        indentedSoftWrap: false,
                        readOnly: !opendxp.globalmanager.get("user").isAllowed('plugin_sylphen_data_bridge_admin_permission') && !opendxp.globalmanager.get("user").isAllowed('Dataport ' + this.dataportId + ' Configuration'),
                        fontFamily: 'Courier New, Courier, monospace;',
                        enableBasicAutocompletion: true,
                        enableSnippets: true,
                        enableLiveAutocompletion: true
                    });

                    //set data
                    if (settings.calculation) {
                        editor.setValue(settings.calculation);
                        editor.clearSelection();
                    } else {
                        editor.setValue(t('pim.mapping.callback_function.code_placeholder'));
                    }

                    callbackFunctionEditor = editor;
                }.bind(this)
            }
        });

        if(typeof AvailableVariablesModel === "undefined") {
            Ext.define('AvailableVariablesModel', {
                extend: 'Ext.data.Model',
                fields: [
                    {name: 'text', type: 'string'}
                ]
            });
        }

        var envVariables = [];
        var envVariablesData = record.get('virtualFields');
        for(var envName in envVariablesData) {
            if(envVariablesData.hasOwnProperty(envName)) {
                envVariables.push({ text: '{{ '+ envName.replace('__virtual_', '')+' }}', qtip: t('pim.mapping.variables.tooltip').replace('%s', envName.replace('__virtual_', ''))+'<hr><xmp>'+ envVariablesData[envName]+'</xmp>', leaf: true });
            }
        }

        var mappedFieldItems = [];
        Ext.each((this.getStore().getData().getSource() || this.getStore().getData()).getRange(), function(otherRecord) {
            if(record.get('attributeKey') !== otherRecord.get('attributeKey') && (otherRecord.get('field') || (otherRecord.get('settings') && otherRecord.get('settings').calculation)) && ['__result_callback', '__result_action', '__init_action'].indexOf(otherRecord.get('attributeKey')) === -1) {
                for(var i=0;i<mappedFieldItems.length;i++) {
                    if('{{ '+otherRecord.get('attributeKey').replace('__virtual_', '')+' }}' === mappedFieldItems[i].text) {
                        return true;
                    }
                }
                mappedFieldItems.push({ text: '{{ '+otherRecord.get('attributeKey').replace('__virtual_', '')+' }}', qtip: t('pim.mapping.variables.tooltip').replace('%s', otherRecord.get('attributeName').replace('__virtual_', ''))+'"<hr><xmp>'+otherRecord.get('example_value')+'</xmp>', leaf: true });
            }
        });

        mappedFieldItems.sort(function (field1, field2) {
            return field1.text.localeCompare(field2.text);
        });

        var genericVariables = [];
        if(['__result_callback', '__result_action', '__init_action'].indexOf(record.get('attributeKey')) === -1) {
            genericVariables.push(
                {
                    text: this.callbackLanguage === 'php' ? '$params[\'value\']' : 'params.value',
                    qtip: (String(record.get('rawValue')).length > 300 ? '(' + t('pim.mapping.variables.double_click') + ')<br>' : '') + t('pim.mapping.variables.value') + '<hr><xmp>' + record.get('rawValue') + '</xmp>',
                    leaf: true
                }
            );

            var rawItemData;
            try {
                rawItemData = record.get('rawItemData');

                var rawItemDataFields = [];
                Ext.Object.each(rawItemData, function(rawItemFieldName, rawItemFieldData) {
                    rawItemFieldData = JSON.stringify(rawItemFieldData.value, null, 2);
                    rawItemDataFields.push({
                        text: this.callbackLanguage === 'php' ? '$params[\'rawItemData\'][\''+rawItemFieldName+'\'][\'value\']' : 'params.rawItemData.'+rawItemFieldName,
                        qtip: (rawItemFieldData.length > 300 ? '(' + t('pim.mapping.variables.double_click') + ')<br>' : '') + t('pim.mapping.variables.rawItemData.single').replace('%s', rawItemFieldName) + '<hr><xmp>' + rawItemFieldData + '</xmp>',
                        leaf: true
                    });
                }.bind(this));

                var rawItemDataJson = JSON.stringify(rawItemData, null, 4);
                genericVariables.push({
                    text: this.callbackLanguage === 'php' ? '$params[\'rawItemData\']' : 'params.rawItemData',
                    qtip: (rawItemDataJson.length > 300 ? '(' + t('pim.mapping.variables.double_click') + ')<br>' : '') + t('pim.mapping.variables.rawItemData') + '<hr><xmp>' + rawItemDataJson + '</xmp>',
                    children: rawItemDataFields
                });
            } catch(e) {
                var rawItemDataJson = JSON.stringify(rawItemData, null, 4);
                genericVariables.push({
                    text: this.callbackLanguage === 'php' ? '$params[\'rawItemData\']' : 'params.rawItemData',
                    qtip: (rawItemDataJson.length > 300 ? '(' + t('pim.mapping.variables.double_click') + ')<br>' : '') + t('pim.mapping.variables.rawItemData') + '<hr><xmp>' + rawItemDataJson + '</xmp>',
                    leaf: true
                });
            }

            if (this.dataportPanel.dataportPanel.getForm().findField('itemClass').getValue() !== '0') {
                genericVariables.push(
                    {
                        text: this.callbackLanguage === 'php' ? '$params[\'currentValue\']' : 'params.currentValue',
                        qtip: (String(record.get('currentValue')).length > 300 ? '(' + t('pim.mapping.variables.double_click') + ')<br>' : '') + t('pim.mapping.variables.currentValue').replace('%s', record.get('attributeName')) + '<hr><xmp>' + record.get('currentValue') + '</xmp>',
                        leaf: true
                    });

                try {
                    currentObjectData = JSON.parse(record.get('currentObjectData'));

                    var currentObjectDataFields = [];
                    Ext.Object.each(currentObjectData, function (fieldName, fieldData) {
                        fieldData = JSON.stringify(fieldData, null, 2);
                        currentObjectDataFields.push({
                            text: this.callbackLanguage === 'php' ? '$params[\'currentObjectData\'][\'' + fieldName + '\']' : 'params.currentObjectData.' + fieldName,
                            qtip: (fieldData.length > 300 ? '(' + t('pim.mapping.variables.double_click') + ')<br>' : '') + t('pim.mapping.variables.currentObjectData.single').replace('%s', fieldName) + '<hr><xmp>' + fieldData + '</xmp>',
                            leaf: true
                        });
                    }.bind(this));

                    genericVariables.push({
                        text: this.callbackLanguage === 'php' ? '$params[\'currentObjectData\']' : 'params.currentObjectData',
                        qtip: (String(record.get('currentObjectData')).length > 300 ? '(' + t('pim.mapping.variables.double_click') + ')<br>' : '') + t('pim.mapping.variables.currentObjectData') + '<hr><xmp>' + record.get('currentObjectData') + '</xmp>',
                        children: currentObjectDataFields
                    });
                } catch (e) {
                    genericVariables.push({
                        text: this.callbackLanguage === 'php' ? '$params[\'currentObjectData\']' : 'params.currentObjectData',
                        qtip: (String(record.get('currentObjectData')).length > 300 ? '(' + t('pim.mapping.variables.double_click') + ')<br>' : '') + t('pim.mapping.variables.currentObjectData') + '<hr><xmp>' + record.get('currentObjectData') + '</xmp>',
                        leaf: true
                    });
                }

                genericVariables.push(
                    {
                        text: this.callbackLanguage === 'php' ? '$params[\'keyValues\']' : 'params.keyValues',
                        qtip: (String(record.get('keyValues')).length > 300 ? '(' + t('pim.mapping.variables.double_click') + ')<br>' : '') + t('pim.mapping.variables.keyValues') + '<hr><xmp>' + record.get('keyValues') + '</xmp>',
                        leaf: true
                    }
                );

                var nameParts = record.get('attributeKey').split('#');
                genericVariables.push({
                    text: this.callbackLanguage === 'php' ? '$params[\'field\']' : 'params.field',
                    qtip: t('pim.dataport-fields-name') + '<hr><xmp>' + nameParts[0] + '</xmp>',
                    leaf: true
                });
                if (nameParts.length === 2) {
                    genericVariables.push({
                        text: this.callbackLanguage === 'php' ? '$params[\'locale\']' : 'params.locale',
                        qtip: t('language') + '<hr><xmp>' + nameParts[1] + '</xmp>',
                        leaf: true
                    });
                }
            }

            genericVariables.push(
                { text: '$params[\'context\']', qtip: t('pim.mapping.variables.context'), leaf: true },
                { text: '$params[\'transfer\']', qtip: t('pim.mapping.variables.transfer'), leaf: true }
            );
        } else if(record.get('attributeKey') === '__result_callback' && this.callbackLanguage==='php') {
            var rawItemData;
            try {
                rawItemData = record.get('rawItemData');

                var rawItemDataFields = [];
                Ext.Object.each(rawItemData, function (rawItemFieldName, rawItemFieldData) {
                    rawItemFieldData = JSON.stringify(rawItemFieldData.value, null, 2);
                    rawItemDataFields.push({
                        text: this.callbackLanguage === 'php' ? '$params[\'rawItemData\'][\'' + rawItemFieldName + '\'][\'value\']' : 'params.rawItemData.' + rawItemFieldName,
                        qtip: (rawItemFieldData.length > 300 ? '(' + t('pim.mapping.variables.double_click') + ')<br>' : '') + t('pim.mapping.variables.rawItemData.single').replace('%s', rawItemFieldName) + '<hr><xmp>' + rawItemFieldData + '</xmp>',
                        leaf: true
                    });
                }.bind(this));

                var rawItemDataJson = JSON.stringify(rawItemData, null, 4);
                genericVariables.push({
                    text: this.callbackLanguage === 'php' ? '$params[\'rawItemData\']' : 'params.rawItemData',
                    qtip: (rawItemDataJson.length > 300 ? '(' + t('pim.mapping.variables.double_click') + ')<br>' : '') + t('pim.mapping.variables.rawItemData') + '<hr><xmp>' + rawItemDataJson + '</xmp>',
                    children: rawItemDataFields
                });
            } catch (e) {
                var rawItemDataJson = JSON.stringify(rawItemData, null, 4);
                genericVariables.push({
                    text: this.callbackLanguage === 'php' ? '$params[\'rawItemData\']' : 'params.rawItemData',
                    qtip: (rawItemDataJson.length > 300 ? '(' + t('pim.mapping.variables.double_click') + ')<br>' : '') + t('pim.mapping.variables.rawItemData') + '<hr><xmp>' + rawItemDataJson + '</xmp>',
                    leaf: true
                });
            }
            genericVariables.push(
                { text: '$params[\'response\']', qtip: t('pim.mapping.variables.response'), leaf: true },
                { text: '$params[\'request\']', qtip: t('pim.mapping.variables.request'), leaf: true },
                { text: '$params[\'context\']', qtip: t('pim.mapping.variables.context'), leaf: true},
                { text: '$params[\'logs\']', qtip: t('pim.mapping.variables.logs'), leaf: true},
                { text: '$params[\'objectIDs\']', qtip: t('pim.mapping.variables.objectIDs'), leaf: true},
                { text: '$params[\'lastCall\']', qtip: t('pim.mapping.variables.lastCall'), leaf: true},
                { text: '$params[\'transfer\']', qtip: t('pim.mapping.variables.transfer'), leaf: true}
            );
        } else if (record.get('attributeKey') === '__result_action' && this.callbackLanguage === 'php') {
            genericVariables.push(
              { text: '$params[\'response\']', qtip: t('pim.mapping.variables.response.action'), leaf: true },
              { text: '$params[\'request\']', qtip: t('pim.mapping.variables.request'), leaf: true },
              { text: '$params[\'transfer\']', qtip: t('pim.mapping.variables.transfer.action'), leaf: true },
              { text: '$params[\'context\']', qtip: t('pim.mapping.variables.context'), leaf: true }
            );
        } else if (record.get('attributeKey') === '__init_action' && this.callbackLanguage === 'php') {
            genericVariables.push(
              { text: '$params[\'response\']', qtip: t('pim.mapping.variables.response.action'), leaf: true },
              { text: '$params[\'request\']', qtip: t('pim.mapping.variables.request'), leaf: true },
              { text: '$params[\'transfer\']', qtip: t('pim.mapping.variables.transfer.action'), leaf: true },
              { text: '$params[\'context\']', qtip: t('pim.mapping.variables.context'), leaf: true }
            );
        }

        if (this.callbackLanguage === 'php') {
            genericVariables.push({
                text: '$params[\'logger\']',
                qtip: t('pim.mapping.variables.logger'),
                leaf: true
            });

            genericVariables.push({
                text: '$params[\'translator\']',
                qtip: t('pim.mapping.variables.translator'),
                leaf: true
            });
        }

        var variableTreeItems = [
            {
                text: t('pim.mapping.variables.generic'),
                expanded: true,
                children: genericVariables
            }
        ];

        if(mappedFieldItems.length > 0) {
            variableTreeItems.push({
                text: t('pim.mapping.variables.mapped'),
                expanded: false,
                children: mappedFieldItems
            });
        }

        if(envVariables.length > 0) {
            variableTreeItems.push({
                text: t('pim.mapping.variables.env'),
                expanded: false,
                children: envVariables
            });
        }

        var variableStore = Ext.create('Ext.data.TreeStore', {
            root: {
                expanded: true,
                children: variableTreeItems
            }
        });

        var lastCopied = Date.now();
        var variablePanel = Ext.create('Ext.tree.Panel', {
            title: t('pim.mapping.variables.available_variables'),
            store: variableStore,
            border: false,
            scrollable: true,
            rootVisible: false,
            listeners: {
                itemdblclick: function(panel, record) {
                    if(!record.hasChildNodes() || record.get('text').substr(0,1) === '$') {
                        var variableWindow = new Ext.Window({
                            layout: 'fit',
                            title: t('pim.mapping.variables.variable') + ' ' + record.get('text'),
                            width: '50%',
                            minWidth: 500,
                            height: 400,
                            closable: true,
                            resizable: true,
                            maximizable: true,
                            draggable: true,
                            modal: true,
                            items: [{
                                xtype: 'panel',
                                html: record.get('qtip').replace('(' + t('pim.mapping.variables.double_click') + ')<br>', ''),
                                padding: 15,
                                scrollable: true
                            }],
                        });
                        variableWindow.show();
                    }
                },
                itemclick: function (panel, record) {
                    var now = Date.now();
                    if(lastCopied < now - 500) {
                        opendxp.helpers.copyStringToClipboard(record.get('text'));
                        opendxp.helpers.showNotification(t("info"), t('pim.mapping.copied_to_clipboard'), 'info');
                        lastCopied = now;
                    }
                }
            }
        });

        var fieldMappingRecord = record;
        var historyPanel = Ext.create('Ext.grid.Panel', {
            title: t('pim.mapping.variables.history'),
            store: Ext.create('Ext.data.Store', {
                fields: ['date', 'user', 'calculation', 'compare', 'timestamp'],
                data: record.get('history')
            }),
            columns: [
                {
                    header: t('date'),
                    width: 150,
                    dataIndex: 'date',
                    xtype: 'datecolumn',
                    format: 'd.m.Y H:i',
                    renderer: function (value, metaData, record) {
                        metaData.tdAttr = 'data-qtip="' + Ext.util.Format.htmlEncode(record.get('compare')).replace(/([^>\r\n]?)(\r\n|\n\r|\r|\n)/g, '<br>') + '"';
                        return value;
                    }
                },
                {
                    text: t("user"),
                    dataIndex: 'user',
                    filter: 'list',
                    flex: 1,
                    renderer: function (value, metaData, record) {
                        metaData.tdAttr = 'data-qtip="' + Ext.util.Format.htmlEncode(record.get('compare')).replace(/([^>\r\n]?)(\r\n|\n\r|\r|\n)/g, '<br>') + '"';
                        return value;
                    }
                }
            ],
            border: false,
            scrollable: true,
            listeners: {
                rowclick: function (grid, record) {
                    var compareWindow = new Ext.Window({
                        layout: 'fit',
                        title: t('pim.mapping.variables.history.compare_with') + ': ' + fieldMappingRecord.get('attributeName'),
                        width: '50%',
                        minWidth: 500,
                        height: 400,
                        closable: true,
                        maximizable: true,
                        resizable: true,
                        draggable: true,
                        modal: true,
                        items: [{
                            xtype: 'panel',
                            html: record.get('compare'),
                            padding: 15,
                            scrollable: true
                        }],
                    });
                    compareWindow.show();
                },
                rowcontextmenu: function (grid, record, tr, rowIndex, e) {
                    var menu = new Ext.menu.Menu();

                    menu.add(new Ext.menu.Item({
                        text: t('restore'),
                        iconCls: "opendxp_icon_reverseObjectRelation",
                        handler: function () {
                            callbackFunctionEditor.setValue(record.get('calculation'));
                        }.bind(this)
                    }));

                    menu.add(new Ext.menu.Item({
                        text: t('copy'),
                        iconCls: "opendxp_icon_copy",
                        handler: function () {
                            opendxp.helpers.copyStringToClipboard(record.get('calculation'));
                            opendxp.helpers.showNotification(t("info"), t('pim.mapping.copied_to_clipboard_mapping'), 'info');
                        }.bind(this)
                    }));

                    e.stopEvent();
                    menu.showAt(e.pageX, e.pageY);
                }.bind(this)
            }
        });

        var accordion = Ext.create('Ext.panel.Panel', {
            flex: 160,
            height: '100%',
            layout: {
                type: 'accordion',
                animate: true
            },
            items: [variablePanel, historyPanel]
        });

        var calculationPanel = Ext.create('Ext.panel.Panel', {
            layout: {
                type: 'hbox'
            },
            flex: 1,
            items: [
                Ext.create('Ext.panel.Panel', {
                    layout: 'fit',
                    height: '100%',
                    flex: 280,
                    items: [
                        editorContainer
                    ]
                }),
                {
                    xtype: 'button',
                    height: '100%',
                    text: '>',
                    handler: function(button) {
                        if(accordion.collapsed) {
                            accordion.expand();
                            accordion.isCollapsingOrExpanding = 0; // due to a bug this does not get reset by ExtJS allowing to toggle / collapse only twice (1x collapse, 1x expand)
                            button.setText('>');
                        } else {
                            accordion.collapse(Ext.Component.DIRECTION_RIGHT);
                            accordion.isCollapsingOrExpanding = 0;
                            button.setText('<');
                        }

                        if (!callbackFunctionEditor.completer) {
                            // make sure completer is initialized
                            callbackFunctionEditor.execCommand("startAutocomplete");
                            callbackFunctionEditor.completer.detach();
                        }
                        var popup = callbackFunctionEditor.completer.popup;
                        popup.container.style.width = (callbackFunctionEditor.container.offsetWidth - 80) + 'px';
                        popup.resize();
                        callbackFunctionEditor.resize();
                    }
                },
                accordion
            ]
        });
        fields.push(calculationPanel);

        var settingsForm = Ext.create('Ext.form.Panel', {
            border: false,
            padding: 15,
            defaults: {
                width: 600,
                labelWidth: 150,
                margin:0
            },

            layout: {
                type: 'vbox',
                align: 'stretch'
            },
            items: fields
        });

        if (!opendxp.globalmanager.get("user").isAllowed('plugin_sylphen_data_bridge_admin_permission') && !opendxp.globalmanager.get("user").isAllowed('Dataport ' + this.dataportId + ' Configuration')) {
            settingsForm.getForm().getFields().each(function (field) {
                field.setReadOnly(true);
            });
        }

        var settingsWindow = new Ext.Window({
            layout: 'fit',
            title: t('pim.settingspanel.caption')+' ('+record.get('attributeName')+')',
            width: '80%',
            minWidth: 600,
            height: '80%',
            closable: true,
            resizable: true,
            maximizable: true,
            draggable: true,
            modal: true,
            items: [settingsForm],
            listeners: {
                close: function (window) {
                    callbackFunctionEditor.destroy();
                },
                resize: function() {
                    if (!callbackFunctionEditor.completer) {
                        // make sure completer is initialized
                        callbackFunctionEditor.execCommand("startAutocomplete");
                        callbackFunctionEditor.completer.detach();
                    }
                    var popup = callbackFunctionEditor.completer.popup;
                    if(popup) {
                        popup.container.style.width = (callbackFunctionEditor.container.offsetWidth - 80) + 'px';
                        popup.resize();
                    }
                    callbackFunctionEditor.resize();
                }
            },
            buttons: [
                {
                    xtype: 'button',
                    iconCls: "opendxp_icon_save",
                    text: t('pim.mapping.savebutton'),
                    hidden: !opendxp.globalmanager.get("user").isAllowed('plugin_sylphen_data_bridge_admin_permission') && !opendxp.globalmanager.get("user").isAllowed('Dataport ' + this.dataportId + ' Configuration'),
                    handler: function (button) {
                        var formPanel = settingsForm;
                        var form = formPanel.getForm();
                        var values = form.getFieldValues();

                        var settings = {
                            keyMapping: values.keyMapping === true,
                            calculation: callbackFunctionEditor.getValue()
                        };

                        if(settings.calculation == t('pim.mapping.callback_function.code_placeholder')) {
                            settings.calculation = '';
                        }

                        if (format) {
                            settings.format = {};
                            if (format.hasOwnProperty('decimalSeparator')) {
                                settings.format.decimalSeparator = values.decimalSeparator;
                            }
                            if (format.hasOwnProperty('groupingSeparator')) {
                                settings.format.groupingSeparator = values.groupingSeparator;
                            }
                            if (format.hasOwnProperty('separator')) {
                                settings.format.separator = values.separator;
                            }
                            if (format.hasOwnProperty('autoCreate')) {
                                settings.format.autoCreate = values.autoCreate;
                            }
                            if (format.hasOwnProperty('dateFormat')) {
                                settings.format.dateFormat = values.dateFormat;
                            }
                            if (format.hasOwnProperty('translateFromLanguage')) {
                                settings.format.translateFromLanguage = values.translateFromLanguage;
                            }
                            if (format.hasOwnProperty('auto_classification')) {
                                settings.format.auto_classification = values.auto_classification;
                            }
                            if (format.hasOwnProperty('auto_create_references')) {
                                settings.format.auto_create_references = values.auto_create_references;
                            }
                            if (format.hasOwnProperty('autoCreateUnits')) {
                                settings.format.autoCreateUnits = values.autoCreateUnits;
                            }
                            if (format.hasOwnProperty('generateText')) {
                                settings.format.generateText = values.generateText;
                            }
                            if (format.hasOwnProperty('textLength')) {
                                settings.format.textLength = values.textLength;
                            }
                            if (format.hasOwnProperty('infer')) {
                                settings.format.infer = values.infer;
                                if(typeof settings.format.infer === 'object') {
                                    settings.format.infer = settings.format.infer.join(',');
                                }
                            }
                            if (values.hasOwnProperty('openAiKey')) {
                                settings.format.openAiKey = values.openAiKey;
                            }
                            if (values.hasOwnProperty('deeplApiKey')) {
                                settings.format.deeplApiKey = values.deeplApiKey;
                            }

                            settings.format.overwrite = values.overwrite === true;
                            settings.format.preventDuplicates = values.preventDuplicates === true;
                            settings.format.writeProtected = values.writeProtected === true;
                            settings.format.purgeitems = values.purgeitems === true;
                            settings.format.deleteChildren = values.deleteChildren === true;
                            settings.format.optimize = values.optimize;
                            settings.format.auto_generate_fields = values.auto_generate_fields === true;
                        }

                        record.set('settings', settings);
                        formPanel.findParentByType('window').close();

                        if (this.queryById('saveButton').isHidden()) {
                            this.getStore().sync();
                        }
                    }.bind(this)
                }
            ]
        });

        settingsWindow.show();
    }
});