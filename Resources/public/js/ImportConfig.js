/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


opendxp.registerNS("opendxp.plugin.Pim.ImportConfig");

opendxp.plugin.Pim.ImportConfig = Class.create({

    dataportContainer: null,
    history: [],
    historyPointer: null,

    initialize: function (layoutId) {
        this.layoutId = layoutId;

        this.initTreeModel();
        // create layout
        this.getLayout();
    },
    getDataportTree: function () {
        if (!this.tree) {
            if (typeof RestApiUserModel === "undefined") {
                Ext.define('RestApiUserModel', {
                    extend: 'Ext.data.Model',
                    fields: ['permission_id', 'users_id', {name: 'username', persist: false}, 'api_key', { name: 'dataports', persist: false, defaultValue: [] }, 'valid_to', { name: 'active', persist: false, defaultValue: true }],
                    idProperty: 'permission_id',
                    root: 'permissions',
                    validators: {
                        users_id: { type: 'presence' }
                    }
                });
            }

            var filterCache = {};
            var doItemFilter = function(item, searchValue) {
                if(typeof filterCache[item.get('id')] === "undefined") {
                    filterCache[item.get('id')] = itemFilter(item, searchValue);
                }
                return filterCache[item.get('id')];
            };

            var itemFilter = function(item, searchValue) {
                itemTokens = item.get('text').toLowerCase().split(' ');
                searchValueTokens = searchValue.toLowerCase().split(' ');
                if (searchValueTokens.every(function(searchToken) {
                    for(var itemTokenIndex in itemTokens) {
                        if (itemTokens[itemTokenIndex].indexOf(searchToken) > -1) {
                            return true;
                        }
                    }
                })) {
                    return true;
                }

                for (var i = 0; i < item.childNodes.length; i++) {
                    if(doItemFilter(item.childNodes[i], searchValue)) {
                        return true;
                    }
                }

                return false;
            };
            this.tree = Ext.create('Ext.tree.Panel', {
                id: "Pim_dataport_tree",
                emptyText: t('pim.dataport_empty_tree'),
                region: "west",
                collapsible: false,
                autoScroll: true,
                animate: true,
                containerScroll: true,
                border: true,
                width: 350,
                resizable: true,
                rootVisible: false,
                lines: false,
                store: this.dataStore,
                plugins: {
                    ptype: 'bufferedrenderer'
                },
                listeners: {
                    itemclick: function (sm, node, index, e) {
                        if (node.id) {
                            this.id = node.id;
                            this.onTreeNodeClick();
                        }

                        if(node.hasChildNodes()) {
                            node.expand();
                        }
                    }.bind(this),
                    itemdblclick: function (sm, node, index, e) {
                        if(node.childNodes.length === 0) {
                            opendxp.helpers.copyStringToClipboard('Dataport ' + node.id + ' (' + node.get('name') + ')');
                            opendxp.helpers.showNotification(t("info"), t('pim.mapping.copied_to_clipboard_mapping'), 'info');
                        }
                    },
                    itemcontextmenu: function (view, rec, node, index, event) {
                        event.stopEvent();
                        this.onTreeNodeContextmenu(rec, event);
                    }.bind(this),
                    itemexpand: function(node) {
                        if(node.firstChild && node.firstChild.isExpandable()) {
                            var childCount = 0;
                            node.eachChild(function (child) {
                                if (child.raw.visible) {
                                    childCount++;
                                }

                                if (childCount > 1) {
                                    return false;
                                }
                            });

                            if (childCount === 1) {
                                node.firstChild.expand();
                            }
                        }
                    }
                },

                tbar: {
                    layout: {
                        type: 'hbox',
                        align: 'stretch'
                    },
                    items: [
                        {
                            xtype: 'textfield',
                            emptyText: t('search'),
                            itemId: 'search',
                            listeners: {
                                change: function (textfield) {
                                    if (typeof this.tree.setEmptyText !== "undefined") {
                                        if (textfield.getValue() !== '') {
                                            this.tree.setEmptyText(t('pim.dataport_empty_tree') + '.<br><a href="#" data-createDataportName="' + textfield.getValue() + '">' + t('pim.dataport_create').replace('%s', textfield.getValue()) + '</a>');
                                        } else {
                                            this.tree.setEmptyText(t('pim.dataport_empty_tree'));
                                        }
                                    }

                                    // filterBy call the function for every tree node -> because filtering checks recursively if child nodes contain the search term, filtering would get done multiple times for child items -> result gets cached
                                    filterCache = {};
                                    this.dataStore.filterBy(function (item) {
                                        return doItemFilter(item, textfield.getValue().toLowerCase());
                                    });

                                    var searchValue = this.tree.queryById('search').getValue().toLowerCase();
                                    this.tree.getRootNode().cascade(function () {
                                        if (this.get('text').toLowerCase().indexOf(searchValue) === -1 && this.isExpandable()) {
                                            this.expand();
                                        }
                                    });
                                }.bind(this)
                            },
                            flex: 1
                        },
                        {
                            xtype: 'splitbutton',
                            text: t("pim.add_dataport"),
                            itemId: 'addDataportButton',
                            iconCls: "opendxp_icon_add",
                            handler: this.promptNewDataportName.bind(this),
                            hidden: !opendxp.globalmanager.get("user").isAllowed('plugin_sylphen_data_bridge'),
                            menu: new Ext.menu.Menu({
                                items: [
                                    {
                                        itemId: 'addDataportButton_template_none',
                                        text: t('pim.add_dataport.no_template'),
                                        disabled: true
                                    }
                                ]
                            })
                        }, {
                            iconCls: "opendxp_icon_reload",
                            handler: function () {
                                this.reloadTree();
                            }.bind(this),
                            tooltip: t('reload'),
                            width: 30
                        }
                    ]
                },
                dockedItems: [{
                    xtype: 'button',
                    dock: 'bottom',
                    text: t('permissions')+' &amp; '+t('pim.rest_api.api_keys'),
                    iconCls: 'opendxp_icon_user',
                    handler: function() {
                        var restAuthStore = Ext.create('Ext.data.JsonStore', {
                            model: 'RestApiUserModel',
                            proxy: {
                                type: 'ajax',
                                api: {
                                    read: '/admin/SylphenDataBridge/importconfig/rest-auth',
                                    update: '/admin/SylphenDataBridge/importconfig/rest-auth/update'
                                },
                                reader: {
                                    type: 'json',
                                    rootProperty: 'permissions'
                                },
                                writer: {
                                    type: 'json',
                                    writeAllFields: true
                                }
                            },

                            idProperty: 'permission_id',
                            remoteFilter: true,
                            remoteSort: true,
                            autoSync: true,
                            autoLoad: true,
                            pageSize: 25
                        });

                        this.restAuthPanel = Ext.create('Ext.grid.Panel', {
                            border: false,
                            frame: false,
                            columnLines: true,
                            stripeRows: true,
                            store: restAuthStore,
                            multiSelect: true,
                            columns: [
                                {
                                    header: t('user'),
                                    dataIndex: 'username',
                                    flex: 1,
                                    renderer: function (value, metaData, record) {
                                        if (record.get('valid_to') && new Date(record.get('valid_to')) < new Date()) {
                                            metaData.tdAttr = 'data-qtip="' + t('pim.rest_api.api_key_expired') + '"';
                                        }

                                        if (!record.get('active')) {
                                            metaData.tdAttr = 'data-qtip="' + t('pim.rest_api.user_disabled') + '"';
                                        }

                                        if (value) {
                                            if (!record.get('active') || (record.get('valid_to') && new Date(record.get('valid_to')) < new Date())) {
                                                return '<del>' + value + ((!record.get('active')) ? ' (' + t('disabled') + ')' : '') + '</del>';
                                            }
                                            return value;
                                        } else {
                                            return "";
                                        }
                                    }
                                },
                                {
                                    header: t('pim.Imports'),
                                    flex: 2,
                                    dataIndex: 'dataports',
                                    renderer: function (value, metaData, record) {
                                        if (value.length > 0) {
                                            var output = [];
                                            for (var i in value) {
                                                if (!value[i].allowRun) {
                                                    value[i].name = '<del data-qtip="' + t('pim.permission_no_execution') + '">' + value[i].name + '</del>';
                                                } else if (value[i].targetTypeMissingPermission) {
                                                    value[i].name = '<del data-qtip="' + t('pim.permission_missing_targetType').replace('%s', t(value[i].targetTypeMissingPermission)) + '">' + value[i].name + '</del>';
                                                } else if (value[i].targetClassMissingPermission) {
                                                    value[i].name = '<del data-qtip="' + t('pim.permission_missing_targetClass').replace('%s', value[i].targetClassMissingPermission) + '">' + value[i].name + '</del>';
                                                }

                                                output.push(value[i].name);
                                            }

                                            if (!record.get('active') || (record.get('valid_to') && new Date(record.get('valid_to')) < new Date())) {
                                                return '<del>' + output.join(', ') + '</del>';
                                            }

                                            return output.join(', ');
                                        } else {
                                            return '<i>' + t('none') + '</i>';
                                        }
                                    }
                                },
                                {
                                    header: t('pim.rest_api.api_key'),
                                    width: 265,
                                    dataIndex: 'api_key',
                                    editor: {
                                        xtype: 'textfield',
                                        selectOnFocus: true
                                    },
                                    renderer: function (value, metaData, record) {
                                        if (!value) {
                                            return '';
                                        }

                                        if (!record.get('active')) {
                                            metaData.tdAttr = 'data-qtip="' + t('pim.rest_api.user_disabled') + '"';
                                        }
                                        if (record.get('valid_to') && new Date(record.get('valid_to')) < new Date()) {
                                            metaData.tdAttr = 'data-qtip="' + t('pim.rest_api.api_key_expired') + '"';
                                        }

                                        if (!record.get('active') || (record.get('valid_to') && new Date(record.get('valid_to')) < new Date())) {
                                            return '<del>' + value + '</del>';
                                        }
                                        return value;
                                    }
                                },
                                {
                                    xtype: 'datecolumn',
                                    header: t('pim.rest_api.api_key_valid_to'),
                                    width: 180,
                                    dataIndex: 'valid_to',
                                    sortType: 'asDate',
                                    editor: {
                                        xtype: 'datefield',
                                        format: 'Y-m-d H:i:s'
                                    },
                                    format: 'Y-m-d H:i:s',
                                    renderer: function (value, metaData, record) {
                                        if (!value) {
                                            return '<i>' + t('always') + '</i>';
                                        }

                                        if (!record.get('active')) {
                                            metaData.tdAttr = 'data-qtip="' + t('pim.rest_api.user_disabled') + '"';
                                        }

                                        if (record.get('valid_to') && new Date(record.get('valid_to')) < new Date()) {
                                            metaData.tdAttr = 'data-qtip="' + t('pim.rest_api.api_key_expired') + '"';

                                            metaData.tdCls = 'pim_calculation_invalid';
                                        }

                                        if (!record.get('active') || (record.get('valid_to') && new Date(record.get('valid_to')) < new Date())) {
                                            return '<del>' + value + '</del>';
                                        }
                                        return value;
                                    }
                                }
                            ],
                            plugins: [
                                Ext.create('Ext.grid.plugin.CellEditing', { clicksToEdit: 1 }),
                                'gridfilters'
                            ],
                            listeners: {
                                render: function () {
                                    restAuthStore.load();
                                },
                                beforeedit: function (editor, context, eOpts) {
                                    if (context.field === 'api_key' && !context.value) {
                                        var editorField = context.column.getEditor();
                                        context.record.set('api_key', md5(uniqid()));
                                        context.record.commit();
                                        return false;
                                    }
                                    return true;
                                },
                                cellclick: function (grid, tdElement, columnIndex, record) {
                                    var dataIndex = grid.getHeaderCt().getHeaderAtIndex(columnIndex).dataIndex;
                                    if (dataIndex === 'username') {
                                        opendxp.helpers.showUser(record.get('users_id'));
                                    }

                                    if (dataIndex === 'role') {
                                        opendxp.helpers.showRole(record.get('users_id'));
                                    }
                                }
                            }
                        });

                        new Ext.Window({
                            itemId: 'permissionWindow',
                            title: t('permissions'),
                            width: '80%',
                            height: '80%',
                            layout: 'fit',
                            items: [
                                this.restAuthPanel
                            ]
                        }).show();
                    }.bind(this)
                }]
            });
        }

        Ext.Ajax.request({
            url: '/admin/SylphenDataBridge/importconfig/get-templates',
            success: function (dataportResponse) {
                dataportResponse = Ext.decode(dataportResponse.responseText);

                if (dataportResponse.success) {
                    var templates = dataportResponse.templates;

                    if(templates.length > 0) {
                        this.tree.queryById('addDataportButton').menu.remove('addDataportButton_template_none');

                        Ext.Array.each(templates, function (template) {
                            var menu = template.menu;
                            Ext.Array.each(menu, function (menuItem) {
                                menuItem.handler = function() {
                                    Ext.Ajax.request({
                                        url: '/admin/SylphenDataBridge/importconfig/add-from-template?template='+menuItem['itemId'].replace('add_dataport_template_',''),
                                        success: function (response) {
                                            try {
                                                response = Ext.decode(response.responseText);
                                                if (!(response && response.success)) {
                                                    opendxp.helpers.showNotification(t("error"), t(response.errorMessage), "error");
                                                } else {
                                                    this.tree.getStore().on('load', function () {
                                                        opendxp.globalmanager.get("user").permissions.push('Dataport ' + response.dataport.id + ' Configuration');
                                                        opendxp.globalmanager.get("user").permissions.push('Dataport ' + response.dataport.id + ' Execution');

                                                        this.id = response.dataport.id;
                                                        this.onTreeNodeClick();
                                                    }, this, { single: true });

                                                    this.reloadTree();

                                                    if (response.errorMessage) {
                                                        opendxp.helpers.showNotification(t("info"), t(response.errorMessage), 'info');
                                                    }
                                                }
                                            } catch (e) {
                                                opendxp.helpers.showNotification(t("error"), t("pim.error_creating_dataport"), "error");
                                            }
                                        }.bind(this)
                                    });
                                }.bind(this);
                            }.bind(this));

                            var menuItem = template;
                            menuItem.menu = menu;

                            this.tree.queryById('addDataportButton').menu.add(menuItem);
                        }.bind(this));
                    }
                }
            }.bind(this)
        });

        return this.tree;
    },

    initTreeModel: function () {
        if(typeof ImportDataPortModel === "undefined") {
            Ext.define('ImportDataPortModel', {
                extend: 'Ext.data.Model',
                fields: [
                    {name: 'index', type: 'int', defaultValue: -1, persist: true},
                    {name: 'id', type: 'string'},
                    {name: 'name', type: 'string', convert: function(v, record) {
                        return record.get('text');
                    }},
                    { name: 'sourcetype', type: 'string' },
                    {name: 'text', type: 'string', convert: function(v, record) {
                            if(record.get('iconCls') !== 'opendxp_icon_input' && record.get('id')) {
                                var isFavorite = false;
                                var traverseFavorites = function (favorites) {
                                    Ext.Object.each(favorites, function (userId, favorite) {
                                        if (Object.keys(favorite.children).length > 0) {
                                            traverseFavorites(favorite.children);
                                        } else if (favorite.favorite && favorite.currentUserHasRole) {
                                            isFavorite = true;
                                        }

                                        if(isFavorite) {
                                            return false; // break loop
                                        }
                                    });
                                };

                                traverseFavorites(record.get('favorite'));
                                var indentSpaces = 4 - record.get('id').length;
                                if(indentSpaces < 0) {
                                    indentSpaces = 0;
                                }
                                var returnName = (isFavorite ? '<b style="font-weight: bold">' : '')+ (record.get('id')+': ') + Array(indentSpaces).join('&ensp;') + v + (record.get('favorite') ? '</b>' : '');

                                if (record.get('unused')) {
                                    returnName = '<s title="' + t('pim.manual.statusgrid.status.unused') + '">' + returnName + '</s>'
                                }

                                if (record.get('error')) {
                                    returnName = '<span style="color: red" title="' + t('pim.manual.statusgrid.status.error_occurred') + '">' + returnName + '</span>'
                                }

                                return returnName;
                            }
                            return v;
                        }},
                    { name: 'icon', type: 'string', defaultValue: '/bundles/opendxpadmin/img/flat-color-icons/feed_in.svg' },
                    { name: 'iconCls', type: 'string', defaultValue: '' },
                    { name: 'expandable', type: 'boolean', defaultValue: true, persist: true, convert: null },
                    { name: 'allowDrop', type: 'boolean', defaultValue: false, persist: false, convert: null },
                    { name: 'favorite', type: 'auto', persist: false, convert: null },
                    { name: 'error', type: 'boolean', defaultValue: false, persist: false, convert: null },
                    { name: 'unused', type: 'boolean', defaultValue: false, persist: false, convert: null }
                ],
                proxy: {
                    type: 'ajax',
                    url: '/admin/SylphenDataBridge/importconfig/get-dataports',
                    timeout: this.readAjaxTimeoutFromConfig(),
                    reader: {
                        type: 'json',
                        transform: function (data) {
                            Ext.each(data, function (field) {
                                field.name = field.text;
                            });
                            return data;
                        }
                    }
                }
            });
        }

        this.dataStore = Ext.create('Ext.data.TreeStore', {
            model: 'ImportDataPortModel',
            root: {
                name: 'Data',
                expanded: true
            }
        });
    },

    readAjaxTimeoutFromConfig: function () {
        let timeout = 30000;
        Ext.Ajax.request({
            url: '/SylphenDataBridge/read-ajax-timeout-from-config',
            async: false,
            method: 'GET',
            success: function (response) {
                if (JSON.parse(response.responseText).success === true) {
                    timeout = JSON.parse(response.responseText).data * 1000;
                }
            },
            failure: function (response) {
                console.error([
                    'Failed to fetch AJAX timeout from config',
                    JSON.parse(response.responseText).message
                ]);
            }
        });

        return timeout;
    },

    getContainer: function () {
        if (!this.dataportContainer) {
            var readerFields = [
                { name: 'dataportId' },
                { name: 'dataportName' },
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
                { name: 'worstLogType' },
                { name: 'worstLog' },
                { name: 'triggeredBy' }
            ]

            if (typeof JSONImportModel === "undefined") {
                Ext.define('JSONImportModel', {
                        extend: 'Ext.data.Model',
                        fields: readerFields,
                        idProperty: 'id'
                    }
                );
            }

            var timeout;
            var store = Ext.create('Ext.data.JsonStore', {
                model: 'JSONImportModel',

                proxy: {
                    type: 'ajax',
                    url: '/admin/SylphenDataBridge/import/get-status' + ((typeof Intl !== "undefined") ? '?timezone=' + Intl.DateTimeFormat().resolvedOptions().timeZone : ''),
                    extraParams: {
                        'search[]': ['successful', 'errors','running', 'aborted','done > 0']
                    },
                    reader: {
                        type: 'json',
                        rootProperty: 'status',
                        totalProperty: 'total',
                        messageProperty: 'message'
                    },
                    filterParam: 'query'
                },
                pageSize: 25,
                autoLoad: { start: 0, limit: 25 },
                remoteFilter: true,

                listeners: {
                    load: {
                        single: true,
                        fn: function () {
                            (function refresh () {
                                var response = store.getProxy().getReader().rawData;

                                if (typeof response !== "undefined" && (response.queueProcessingError || response.queueItemExists)) {
                                    statusGrid.getComponent('toolbar').getComponent('queue_processing_error_button').show();
                                    if (response.queueProcessingError) {
                                        statusGrid.getComponent('toolbar').getComponent('queue_processing_error_button').setIconCls('opendxp_icon_warning');
                                        statusGrid.getComponent('toolbar').getComponent('queue_processing_error_button').setText(t('pim.manual.start_queue_processor'));
                                        statusGrid.getComponent('toolbar').getComponent('queue_processing_error_button').setTooltip(t('pim.manual.start_queue_processor.tooltip'));
                                    } else {
                                        statusGrid.getComponent('toolbar').getComponent('queue_processing_error_button').setIconCls('queue-processing-monitor-icon');
                                        statusGrid.getComponent('toolbar').getComponent('queue_processing_error_button').setText(t('pim.manual.queue_processor_monitor'));
                                        statusGrid.getComponent('toolbar').getComponent('queue_processing_error_button').setTooltip('');
                                    }
                                } else {
                                    statusGrid.getComponent('toolbar').getComponent('queue_processing_error_button').hide();
                                }

                                timeout = setTimeout(function () {
                                    if (statusGrid.isVisible(true) && store.currentPage === 1 && (typeof window.getSelection === 'undefined' || window.getSelection().toString() === '')) {
                                        var storeExtraParams = store.getProxy().getExtraParams();
                                        if (!storeExtraParams.hasOwnProperty('search') || storeExtraParams.search === '') {
                                            store.on('load', function (store, records, successful) {
                                                if (successful) {
                                                    refresh();
                                                } else {
                                                    store.on('load', function (store, records, successful) {
                                                        if (successful) {
                                                            refresh();
                                                        }
                                                    }, this, { single: true });
                                                }
                                            }, this, { single: true });
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

            var statusGrid = Ext.create('Ext.grid.Panel', {
                title: t('pim.manual.statusgrid.status'),
                closable: false,
                flex: 1,
                store: store,
                plugins: ['gridfilters'],
                cls: 'history-grid',
                dockedItems: [{
                    xtype: 'toolbar',
                    itemId: 'toolbar',
                    dock: 'top',
                    layout: 'hbox',
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
                                    { type: 'done > 1', label: t('pim.manual.filter.done'), customizable: true },
                                    { type: 'total > 1', label: t('pim.manual.filter.total'), customizable: true },
                                ]
                            }),
                            value:['successful', 'running', 'errors', 'aborted', 'done > 0'],
                            displayField: 'label',
                            valueField: 'type',
                            filterPickList: true,
                            forceSelection: false,
                            createNewOnEnter: true,
                            autoSelect: false,
                            flex:1,
                            labelWidth: 50,
                            queryMode: 'local',
                            anyMatch: true,
                            labelTpl: '<tpl if="type!==\'successful\' && type!==\'errors\' && type!==\'queued\' && type!==\'running\' && type!==\'aborted\' && type.indexOf(\'done\') !== 0 && type.indexOf(\'total\') !== 0">' + t('search') + ': </tpl><tpl if="customizable">{type}<tpl else>{label}</tpl>',
                            listeners: {
                                change: function (field, value) {
                                    statusGrid.setLoading(true);
                                    statusGrid.getStore().getProxy().setExtraParam("search[]", field.getValue());
                                    statusGrid.getStore().load();
                                    statusGrid.setLoading(false);
                                }.bind(this),
                                focus: function (combo) {
                                    setTimeout(function () {
                                        if (!combo.isExpanded) {
                                            combo.expand();
                                        }
                                    }, 100);
                                }
                            }
                        }),
                        {
                            text: t('pim.manual.queue_processor_monitor'),
                            itemId: 'queue_processing_error_button',
                            iconCls: 'queue-processing-monitor-icon',
                            width: 200,
                            handler: function (button) {
                                opendxp.helpers.openGenericIframeWindow("data-bridge-queue-monitor", "/admin/SylphenDataBridge/import/start-queue-processing", "queue-processing-monitor-icon", "Data Bridge Queue Monitor");
                            }.bind(this)
                        }
                    ]
                }],
                columns: [
                    {
                        header: t('pim.manual.statusgrid.dataport'),
                        dataIndex: 'dataportName',
                        flex: 1,
                        renderer: function (value, meta, record) {
                            return Ext.String.format('<a href="#" style="color:#00f">{0}</a>', value);
                        }
                    },
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
                        renderer: function (value) {
                            if (value === null) {
                                return '';
                            }

                            var timeParts = [];
                            timeParts.push(Math.floor(value / 3600).toString().padStart(2, '0'));
                            timeParts.push(Math.floor((value %= 3600) / 60).toString().padStart(2, '0'));
                            timeParts.push(Math.floor(value % 60).toString().padStart(2, '0'));

                            return timeParts.join(':');
                        }.bind(this)
                    }, {
                        header: t('source'),
                        dataIndex: 'file',
                        flex: 1,
                        autoSizeColumn: true,
                        renderer: function (value, meta, record) {
                            var file = value;
                            if (value == -1) {
                                file = '(' + t('unknown') + ')';
                            }

                            var sourceElementIds = record.get('sourceElementIDs');
                            if (sourceElementIds.length === 0) {
                                return file;
                            }

                            meta.tdAttr = 'data-qtip="' + t('pim.manual.statusgrid.click_to_open_archive_file') + '"';

                            return Ext.String.format('<a href="#" style="color:#00f">{0}</a>', file);
                        }
                    },
                    {
                        header: t('pim.manual.statusgrid.type'),
                        width: 150,
                        dataIndex: 'type',
                        renderer: function (v, metaData, record) {
                            return t('pim.manual.statusgrid.type.' + v)
                        }
                    },
                    {
                        header: t('pim.manual.statusgrid.status'),
                        width: 150,
                        dataIndex: 'status',
                        renderer: function (v, meta, record) {
                            var status = t('pim.manual.statusgrid.status.' + v);
                            if (v == 1 && record.get('worstLog')) {
                                var logType = record.get('worstLog').substr(1, record.get('worstLog').indexOf(']') - 1);
                                status = t('pim.manual.statusgrid.status.' + logType);
                            }

                            if (record.get('worstLog') !== '') {
                                meta.tdAttr = 'data-qtip="' + record.get('worstLog').replaceAll('"', '&quot;') + '"';
                            } else if (record.get('triggeredBy') !== '') {
                                meta.tdAttr = 'data-qtip="Triggered by: ' + record.get('triggeredBy').replaceAll('"', '&quot;') + '"';
                            }

                            if (record.get('logFile') || v === 'queued') {
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
                            widget.setValue(parseInt(record.get('percentage'), 10) / 100);
                            widget.setText(record.get('percentage'));
                        },
                        widget: {
                            xtype: 'progressbarwidget'
                        }
                    }
                ],
                bbar: opendxp.helpers.grid.buildDefaultPagingToolbar(store, { pageSize: 25 }),
                listeners: {
                    cellclick: function (grid, tdElement, columnIndex, record) {
                        var dataIndex = grid.getHeaderCt().getHeaderAtIndex(columnIndex).dataIndex;
                        if (dataIndex === 'file') {
                            var sourceElementIds = record.get('sourceElementIDs');

                            Ext.each(sourceElementIds, function (sourceElementId) {
                                opendxp.helpers.openElement(sourceElementId, 'asset');
                            });
                        } else if (dataIndex === 'status') {
                            if (record.get('logFile')) {
                                Ext.Ajax.request({
                                    url: '/admin/SylphenDataBridge/importconfig/get',
                                    params: {
                                        id: record.get('dataportId')
                                    },
                                    success: function (dataportResponse) {
                                        dataportResponse = Ext.decode(dataportResponse.responseText);

                                        if (dataportResponse.success) {
                                            var dataportData = dataportResponse.data;

                                            var manualImport = new opendxp.plugin.Pim.ManualImport({
                                                dataportId: record.get('dataportId'),
                                                sourceType: dataportData.sourcetype,
                                                importType: "complete",
                                                isExport: dataportData.itemClass === "0",
                                                isMultiStepWizard: dataportData.itemClass === "0" && dataportData.hasDependentDataport
                                            });
                                            manualImport.showSummaryWindow(record.get('logFile').replace(record.get('dataportId') + '/', ''));
                                        }
                                    }.bind(this)
                                });
                            } else if (record.get('status') === 'queued') {
                                opendxp.helpers.openGenericIframeWindow("data-bridge-queue-monitor", "/admin/SylphenDataBridge/import/start-queue-processing", "queue-processing-monitor-icon", "Data Bridge Queue Monitor");
                            }
                        } else if(dataIndex === 'dataportName') {
                            opendxp.plugin.Pim.plugin.openDataport(record.get('dataportId'));
                        }
                    }.bind(this), itemcontextmenu: function (view, record, item, index, e) {
                        if (record.get('logFileAbsolutePath')) {
                            var xPos = e.getXY()[0];
                            var cols = view.getGridColumns();

                            for (var c in cols) {

                                var leftEdge = cols[c].getPosition()[0];
                                var rightEdge = cols[c].getSize().width + leftEdge;

                                if (xPos >= leftEdge && xPos <= rightEdge) {
                                    if (cols[c].dataIndex === 'status') {
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
                        return (record.get('worstLogType')) ? 'log-' + record.get('worstLogType') : '';
                    }
                }
            });



            this.dataportContainer = new Ext.TabPanel({
                region: "center",
                enableTabScroll: true,
                plugins: [
                    Ext.create('Ext.ux.TabCloseMenu', {
                        pluginId: 'dd_tabclosemenu',
                        showCloseAll: false,
                        closeTabText: t("close_tab"),
                        showCloseOthers: false,
                        extraItemsHead: [{
                            itemId: 'backButton',
                            text: '&larr; '+t('pim.dataport.tab.back'),
                            handler: function (item) {
                                this.historyPointer--;
                                this.id = this.history[this.historyPointer];
                                this.onTreeNodeClick(false);

                                this.dataportContainer.getPlugin('dd_tabclosemenu').menu.queryById('forwardButton').enable();

                                if(this.historyPointer <= 0) {
                                    this.dataportContainer.getPlugin('dd_tabclosemenu').menu.queryById('backButton').disable();
                                }
                            }.bind(this)
                        }, {
                            itemId: 'forwardButton',
                            text: '&rarr; '+t('pim.dataport.tab.forward'),
                            disabled: true,
                            handler: function (item) {
                                this.historyPointer++;
                                this.id = this.history[this.historyPointer];
                                this.onTreeNodeClick(false);

                                this.dataportContainer.getPlugin('dd_tabclosemenu').menu.queryById('backButton').enable();

                                if (this.historyPointer >= this.history.length - 1) {
                                    this.dataportContainer.getPlugin('dd_tabclosemenu').menu.queryById('forwardButton').disable();
                                }
                            }.bind(this)
                        }],
                        extraItemsTail: [{
                            text: t('close_others'),
                            handler: function (menuItem) {
                                var plugin = this.getContainer().getPlugin("dd_tabclosemenu");
                                this.getContainer().items.each(function (child, index, total) {
                                    if (child.getClosable() && child != plugin.item) {
                                        child.close();
                                    }
                                });
                            }.bind(this)
                        }, {
                            text: t('close_all'),
                            handler: function (item) {
                                this.getContainer().items.each(function (child, index, total) {
                                    if(child.getClosable()) {
                                        child.close();
                                    }
                                });
                            }.bind(this)
                        }]
                    }),
                    Ext.create('Ext.ux.TabReorderer', {}),
                    Ext.create('Ext.ux.TabMiddleButtonClose', {})
                ],
                items: [
                    statusGrid
                ],
                listeners: {
                    'tabchange': function (tabpanel, tab) {
                        this.id = tab.dataportId;
                        this.onTreeNodeClick(false);
                    }.bind(this)
                }
            });
        }

        return this.dataportContainer;

    },

    promptNewDataportName: function () {
        Ext.MessageBox.prompt(t('pim.add_dataport'), t('pim.enter_new_dataport_name'), this.addDataport.bind(this), null, null, "");
    },

    addDataport: function (button, value, object) {
        if (button === "ok") {

            Ext.Ajax.request({
                url: "/admin/SylphenDataBridge/importconfig/add",
                params: {
                    name: value
                },
                success: function (response) {
                    try {
                        response = Ext.decode(response.responseText);
                        if (!(response && response.success)) {
                            opendxp.helpers.showNotification(t("error"), t(response.errorMessage), "error");
                        } else {
                            this.tree.getStore().on('load', function () {
                                opendxp.globalmanager.get("user").permissions.push('Dataport ' + response.dataport.id + ' Configuration');
                                opendxp.globalmanager.get("user").permissions.push('Dataport ' + response.dataport.id + ' Execution');

                                this.id = response.dataport.id;
                                this.onTreeNodeClick();
                            }, this, { single: true });

                            this.reloadTree();

                            if(response.errorMessage) {
                                opendxp.helpers.showNotification(t("info"), t(response.errorMessage), 'info');
                            }
                        }
                    } catch (e) {
                        opendxp.helpers.showNotification(t("error"), t("pim.error_creating_dataport"), "error");
                    }
                }.bind(this)
            });
        }
    },

    copyDataport: function (copyRecord, button, name) {
        if (button === "ok") {
            Ext.Ajax.request({
                url: "/admin/SylphenDataBridge/importconfig/copy",
                params: {
                    name: name,
                    copy: copyRecord.id
                },
                success: function (response) {
                    try {
                        response = Ext.decode(response.responseText);
                        if (!(response && response.success)) {
                            opendxp.helpers.showNotification(t("error"), t(response.errorMessage), "error", );
                        }
                    } catch (e) {
                        opendxp.helpers.showNotification(t("error"), t("pim.error_creating_dataport"), "error");
                    }

                    this.tree.getStore().on('load', function() {
                        this.id = response.dataport;
                        this.onTreeNodeClick();
                    }, this, {single: true});

                    this.reloadTree();
                }.bind(this)
            });
        }
    },

    /**
     * create tab panel
     * @returns Ext.Panel
     */
    getLayout: function () {
        var tabPanel = Ext.getCmp("opendxp_panel_tabs");
        var existingTab = Ext.getCmp(this.layoutId);
        if(existingTab) {
            tabPanel.setActiveItem(this.layoutId);
            return existingTab;
        }

        if (!this.layout) {
            this.layout = new Ext.Panel({
                id: this.layoutId,
                title: t('pim.PIM config'),
                icon: "/bundles/opendxpadmin/img/flat-color-icons/feed_in.svg",
                border: false,
                layout: "border",
                closable: true,
                items: [this.getDataportTree(), this.getContainer()],
                listeners: {
                    close: function() {
                        var openTabs = opendxp.helpers.getOpenTab();
                        for (var i = 0; i < openTabs.length; i++) {
                            if (openTabs[i]) {
                                var parts = openTabs[i].split("_");
                                if (parts[1] && parts[0] === "dataport") {
                                    opendxp.helpers.forgetOpenTab('dataport_' + parts[1]);
                                }
                            }
                        }
                    }
                }
            });

            // add event listener
            var layoutId = this.layoutId;
            this.layout.on("destroy", function () {
                opendxp.globalmanager.remove(layoutId);
            }.bind(this));

            // find the main tabpanel and add ours
            tabPanel.add(this.layout);
            tabPanel.setActiveItem(this.layoutId);

            // show the panels by refreshing
            opendxp.layout.refresh();
        }

        return this.layout;
    },

    reloadTree: function () {
        if (this.tree) {
            this.dataStore.reload({
                callback: function () {
                    var focusedDataportPanel = this.getContainer().getActiveTab();
                    if(focusedDataportPanel) {
                        this.id = focusedDataportPanel.dataportId;
                        this.onTreeNodeClick();
                    }

                    this.tree.queryById('search').fireEvent('change', this.tree.queryById('search'));
                }.bind(this)
            });
        }
    },

    getOpenPanels: function (dataportId) {
        var panels = [];
        try {
            var container = this.getContainer();

            container.items.each(function (child, index, total) {
                if (child.dataport && child.dataport.id == dataportId) {
                    panels.push(child);
                }
            });

        } catch (e) {
            console.log(e);
        }
        return panels;
    },

    removeOpenPanel: function (dataportId) {
        var container = this.getContainer();

        var toBeRemoved = this.getOpenPanels(dataportId);
        opendxp.helpers.forgetOpenTab('dataport_' + dataportId);
        Ext.each(toBeRemoved, function (panel) {
            container.remove(panel);
        });
    },

    onTreeNodeClick: function (addToHistory = true) {
        if (this.id > 0) {
            var container = this;

            var existingPanels = container.getOpenPanels(this.id);

            var selectedNode = this.getDataportTree().getSelection()[0];
            if (existingPanels.length > 0) {
                existingPanels[0].show();

                if(typeof selectedNode === "undefined" || selectedNode.id != this.id) {
                    var dataportId = this.id;
                    this.tree.getRootNode().cascade(function () {
                        if (this.id == dataportId) {
                            if(!this.parentNode.data.expanded) {
                                this.parentNode.expand();
                            }

                            selectedNode = this;
                        }
                    });

                    this.tree.setSelection(selectedNode);
                    this.tree.getView().focusRow(selectedNode);
                }
            } else {
                if(typeof selectedNode === "undefined" || selectedNode.id != this.id) {
                    var dataportId = this.id;

                    this.tree.getRootNode().cascade(function () {
                        if (this.id == dataportId) {
                            if(!this.parentNode.data.expanded) {
                                this.parentNode.expand();
                            }

                            selectedNode = this;
                        }
                    });
                }

                if(typeof selectedNode !== "undefined") {
                    if(selectedNode.get('sourcetype') === 'report' && typeof opendxp.report.broker === 'undefined' && typeof opendxp.bundle !== 'undefined' && (typeof opendxp.bundle.customreports === 'undefined' || typeof opendxp.bundle.customreports.broker === 'undefined')) {
                        Ext.MessageBox.alert(t('error'), t('pim.dataport_sourcetype.pimcore_reports.install_report_bundle'));
                        return;
                    }

                    var dataportContainer = container.getContainer();
                    var newItem = Ext.create('opendxp.plugin.Pim.DataportPanel', {
                        dataportId: this.id,
                        icon: selectedNode.get('icon'),
                        importConfigPanel: this
                    });
                    dataportContainer.add(newItem);
                    dataportContainer.setActiveItem(newItem);

                    opendxp.helpers.rememberOpenTab('dataport_' + this.id);

                    this.tree.setSelection(selectedNode);
                }
            }

            if(addToHistory) {
                if (this.history.length === 0 || this.history[this.history.length - 1] !== this.id) {
                    this.history.push(this.id);
                }
                this.historyPointer = this.history.length - 1;
            }
        }
    },

    onTreeNodeContextmenu: function (record, event) {
        var menu = Ext.create('Ext.menu.Menu');

        if(record.childNodes.length === 0) {
            menu.add(Ext.create('Ext.menu.Item', {
                text: t('copy') +' / '+ t('clone'),
                iconCls: "opendxp_icon_copy",
                handler: function () {
                    Ext.Msg.prompt(t('pim.copy_dataport')+' "'+record.get('text')+'"', t('pim.enter_new_dataport_name'), this.copyDataport.bind(this, record), null, false, record.get('name'));
                }.bind(this),
                hidden: !opendxp.globalmanager.get("user").isAllowed('plugin_sylphen_data_bridge')
            }));

            menu.add(Ext.create('Ext.menu.Item', {
                text: t('pim.dataport.import_configuration'),
                iconCls: "opendxp_icon_upload",
                handler: function() {
                    var window = Ext.create('Ext.Window', {
                        layout: {
                            type: 'vbox',
                            align: 'stretch'
                        },
                        title: t('pim.dataport.import_configuration'),
                        width: 500,
                        height: 300,
                        modal: true,
                        autoShow: true,
                        closeAction: 'hide',
                        items: [{
                            xtype: "form",
                            padding: 15,

                            layout: {
                                type: 'vbox',
                                align: 'stretch'
                            },
                            items: [
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
                                    xtype: 'hidden',
                                    name: 'dataportId',
                                    value: record.get('id')
                                }, {
                                    xtype: 'hidden',
                                    name: 'csrfToken',
                                    value: opendxp.settings['csrfToken'],
                                }
                            ]
                        }],
                        buttons: [{
                            text: t('pim.dataport.import.start'),
                            iconCls: "opendxp_icon_save",
                            handler: function (button) {
                                var formPanel = window.down('form');
                                if (formPanel.getForm().isValid()) {
                                    Ext.MessageBox.confirm(t('warning'), t('pim.dataport.import.confirm'), function(btn){
                                        if(btn === 'yes') {
                                            formPanel.submit({
                                                url: '/admin/SylphenDataBridge/importconfig/import',
                                                waitMsg: t('pim.manual.importForm.waitMsg'),
                                                success: function (fp, o) {
                                                    window.destroy();

                                                    this.removeOpenPanel(record.get('id'));
                                                    this.id = record.get('id');
                                                    this.onTreeNodeClick();

                                                    opendxp.helpers.showNotification(t("success"), t('pim.dataport.import.success'), 'success');
                                                }.bind(this),
                                                failure: function (form, action) {
                                                    window.destroy();

                                                    var msg = '';
                                                    if (action.result.msg) {
                                                        msg = '<br>' + t(action.result.msg);
                                                    }

                                                    Ext.MessageBox.alert(t('error'), t('pim.dataport.import.failure') + msg);
                                                }.bind(this)
                                            });
                                        } else {
                                            window.destroy();
                                        }
                                    }.bind(this));
                                }
                            }.bind(this)
                        }]
                    });
                }.bind(this)
            }));

            menu.add(Ext.create('Ext.menu.Item', {
                text: t('pim.dataport.export_configuration'),
                iconCls: "opendxp_icon_download",
                handler: function () {
                    var element = document.createElement('a');
                    element.setAttribute('href', "/admin/SylphenDataBridge/importconfig/download/" + record.get('id'));
                    element.setAttribute('download', record.get('name') + '.json');

                    element.style.display = 'none';
                    document.body.appendChild(element);

                    element.click();

                    document.body.removeChild(element);
                }
            }));

            if(Object.keys(record.get('favorite')).length > 1 && opendxp.globalmanager.get("user").isAllowed('users')) {
                var addOrRemoveFavoriteFunction = function (userId, action) {
                    Ext.Ajax.request({
                        url: "/admin/SylphenDataBridge/importconfig/"+ action+"-favorite",
                        method: 'post',
                        params: {
                            dataportId: record.get('id'),
                            userId: userId
                        },
                        success: function (response) {
                            this.reloadTree();
                        }.bind(this)
                    });
                }.bind(this);

                var createFavoriteMenu = function (favorites) {
                    var menuItems = [];

                    Ext.Object.each(favorites, function(userId, favorite) {
                        var menuItem;
                        if (Object.keys(favorite.children).length > 0) {
                            menuItem = {
                                text: favorite.name,
                                name: favorite.name,
                                iconCls: "opendxp_icon_folder"
                            };
                            menuItem.menu = createFavoriteMenu(favorite.children);
                            menuItem.menu.sort(function(menuItem1, menuItem2) {
                                if(menuItem1.iconCls === 'folder' && menuItem2.iconCls !== 'folder') {
                                    return -1;
                                }
                                if (menuItem1.iconCls !== 'folder' && menuItem2.iconCls === 'folder') {
                                    return 1;
                                }
                                return menuItem1.name.localeCompare(menuItem2.name);
                            });
                        } else if(favorite.favorite) {
                            menuItem = {
                                text: t('pim.dataport.favorite.remove') + ' (' + ((userId == opendxp.globalmanager.get("user").id) ? t('pim.dataport.favorite.personal') : t('role') + ' ' + favorite.name) + ')',
                                iconCls: "opendxp_icon_favourite",
                                name: favorite.name,
                                handler: function() {
                                    addOrRemoveFavoriteFunction(userId, 'remove')
                                }
                            };
                        } else {
                            menuItem = {
                                text: t('pim.dataport.favorite.add') + ' (' + ((userId == opendxp.globalmanager.get("user").id) ? t('pim.dataport.favorite.personal') : t('role') + ' ' + favorite.name) + ')',
                                iconCls: "opendxp_icon_favourite",
                                name: favorite.name,
                                handler: function () {
                                    addOrRemoveFavoriteFunction(userId, 'add')
                                }
                            };
                        }

                        menuItems.push(menuItem);
                    });

                    menuItems.sort(function (menuItem1, menuItem2) {
                        if(menuItem1.name === t('pim.dataport.favorite.personal') && menuItem2.name !== t('pim.dataport.favorite.personal')) {
                            return 1;
                        }
                        if (menuItem1.name !== t('pim.dataport.favorite.personal') && menuItem2.name === t('pim.dataport.favorite.personal')) {
                            return -1;
                        }
                        if (menuItem1.iconCls === 'folder' && menuItem2.iconCls !== 'folder') {
                            return -1;
                        }
                        if (menuItem1.iconCls !== 'folder' && menuItem2.iconCls === 'folder') {
                            return 1;
                        }
                        return menuItem1.name.localeCompare(menuItem2.name);
                    });

                    return menuItems;
                };

                var favoriteMenuItems = createFavoriteMenu(record.get('favorite'));

                var menuItem = Ext.create('Ext.menu.Item', {
                    text: t('pim.dataport.favorite.add'),
                    iconCls: "opendxp_icon_favourite",
                    menu: {
                        cls: 'opendxp_navigation_flyout'
                    }
                });
                for(var i=0;i<favoriteMenuItems.length;i++) {
                    menuItem.getMenu().add(favoriteMenuItems[i]);
                }

                menu.add(menuItem);
            } else {
                if (record.get('favorite')[opendxp.globalmanager.get("user").id].favorite) {
                    menu.add({
                        text: t('pim.dataport.favorite.remove'),
                        iconCls: "opendxp_icon_favourite",
                        handler: function () {
                            Ext.Ajax.request({
                                url: "/admin/SylphenDataBridge/importconfig/remove-favorite",
                                method: 'post',
                                params: {
                                    dataportId: record.get('id')
                                },
                                success: function (response) {
                                    this.reloadTree();
                                }.bind(this)
                            });
                        }.bind(this)
                    });
                } else {
                    menu.add({
                        text: t('pim.dataport.favorite.add'),
                        iconCls: "opendxp_icon_favourite",
                        handler: function () {
                            Ext.Ajax.request({
                                url: "/admin/SylphenDataBridge/importconfig/add-favorite",
                                method: 'post',
                                params: {
                                    dataportId: record.get('id')
                                },
                                success: function (response) {
                                    this.reloadTree();
                                }.bind(this)
                            });
                        }.bind(this)
                    });
                }
            }

            var reportBroker = null;
            if (typeof opendxp.bundle !== "undefined" && typeof opendxp.bundle.customreports !== "undefined" && (typeof opendxp.bundle.customreports === 'undefined' || typeof opendxp.bundle.customreports.broker !== "undefined")) {
                reportBroker = opendxp.bundle.customreports.broker;
            } else if (typeof opendxp.report.broker !== "undefined") {
                reportBroker = opendxp.report.broker;
            }
            if (reportBroker !== null) {
                var reportRecord = null;
                if (reportBroker.reports.hasOwnProperty('Data Bridge')) {
                    var reportName = record.get('name').replace(/[^A-Za-z0-9\-\.~_]+/g, '-');
                    Ext.each(reportBroker.reports['Data Bridge'], function (report) {
                        if (reportName === report.config.name) {
                            reportRecord = report.config;
                            return false;
                        }
                    });
                }

                if (reportRecord === null) {
                    menu.add(Ext.create('Ext.menu.Item', {
                        text: t('pim.dataport.create_report'),
                        iconCls: "opendxp_icon_custom_report_default",
                        handler: function () {
                            Ext.Ajax.request({
                                url: "/admin/SylphenDataBridge/importconfig/create-report",
                                method: 'post',
                                params: {
                                    dataportId: record.get('id')
                                },
                                success: function (response) {
                                    response = Ext.decode(response.responseText);
                                    if (!response || !response.success) {
                                        if (response.message) {
                                            opendxp.helpers.showNotification(t("error"), response.message, "error");
                                        } else {
                                            opendxp.helpers.showNotification(t("error"), 'Could not create report', "error");
                                        }
                                        return;
                                    }

                                    var toolbar;
                                    var reportClass;
                                    if(typeof customreports !== "undefined") {
                                        toolbar = customreports;
                                        reportClass = 'opendxp.bundle.customreports.custom.report';
                                    } else {
                                        toolbar = opendxp.globalmanager.get("layout_toolbar");

                                        reportClass = 'opendxp.report.custom.report';
                                    }

                                    reportBroker.addGroup('Data Bridge', 'Data Bridge', 'opendxp_icon_data_bridge');

                                    reportBroker.addReport(reportClass, 'Data Bridge', {
                                        name: response.name,
                                        text: response.niceName,
                                        niceName: response.niceName,
                                        iconCls: response.iconClass
                                    });

                                    toolbar.showReports(reportClass, {
                                        name: response.name,
                                        text: response.niceName,
                                        niceName: response.niceName,
                                        iconCls: response.iconClass
                                    });
                                }.bind(this)
                            });
                        }.bind(this)
                    }));
                } else {
                    menu.add(Ext.create('Ext.menu.Item', {
                        text: t('pim.dataport.open_report'),
                        iconCls: "opendxp_icon_custom_report_default",
                        handler: function () {
                            var toolbar = opendxp.globalmanager.get("layout_toolbar");

                            toolbar.showReports('opendxp.report.custom.report', {
                                name: reportRecord.name,
                                text: reportRecord.niceName,
                                niceName: reportRecord.niceName,
                                iconCls: reportRecord.iconClass
                            });
                        }.bind(this)
                    }));
                }
            }
        }

        menu.add(Ext.create('Ext.menu.Item', {
            text: t('delete'),
            iconCls: "opendxp_icon_delete",
            handler: function () {
                var records = record.childNodes;
                if(record.childNodes.length === 0) {
                    records = [record];
                }

                Ext.Msg.confirm(t('delete'), t('pim.delete_dataport_message'), function (btn) {
                    if (btn === 'yes') {
                        for(var i=0;i<records.length;i++) {
                            Ext.Ajax.request({
                                url: "/admin/SylphenDataBridge/importconfig/delete/"+records[i].id,
                                success: function(response) {
                                    response = Ext.decode(response.responseText);

                                    this.removeOpenPanel(response.dataportId);
                                    this.reloadTree();
                                }.bind(this)
                            });
                        }
                    }
                }.bind(this));
            }.bind(this)
        }));

        menu.showAt(event.getXY());
    }
});