/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


opendxp.registerNS("opendxp.plugin.Pim.ImportConfig");

opendxp.plugin.Pim.DataportPreview = Ext.extend(Ext.Panel, {
    dataportId: null,
    grid: null,
    sourceType: null,
    columnsCollapsed: false,
    mappingPanel: null,

    initComponent: function () {
        Ext.apply(this, {
            layout: 'fit'
        });

        this.on('afterrender', this.rebuild.bind(this));
        opendxp.plugin.Pim.DataportPreview.superclass.initComponent.call(this);
    },

    rebuild: function () {
        this.removeAll();

        Ext.Ajax.request({
            url: "/admin/SylphenDataBridge/importconfig/get-previewgrid-config",
            params: {
                dataportId: this.dataportId
            },
            success: function (response) {
                try {
                    response = Ext.decode(response.responseText);
                    if (!(response && response.success)) {
                        opendxp.helpers.showNotification(t("error"), t(response.errorMessage), "error", t(response.errorMessage));
                    } else {
                        this.addGrid(response.columns);
                    }
                } catch (e) {
                    console.error(e);
                    opendxp.helpers.showNotification(t("error"), t("pim.error_loading_dataport"), "error");
                }
            }.bind(this)
        });
    },

    addGrid: function (columns) {
        var self = this;

        var readerFields = [
            {name: 'file'},
            {name: 'locale'},
            {name: 'id'},
            {name: 'updated'}
        ];

        var fileStore = new Ext.data.JsonStore({
            proxy: {
                type: 'ajax',
                url: '/admin/SylphenDataBridge/importconfig/get-dataport-resources/' + this.dataportId,
                reader: {
                    type: 'json',
                    rootProperty: 'dataportResources'
                }
            },
            fields: ['id', 'text']
        });

        var localeStore = new Ext.data.JsonStore({
            proxy: {
                type: 'ajax',
                url: '/admin/SylphenDataBridge/importconfig/get-dataport-resource-locales/' + this.dataportId,
                reader: {
                    type: 'json',
                    rootProperty: 'dataportResourceLocales'
                }
            },
            fields: ['id', 'text']
        });

        var importRawDataItem = function(records) {
            Ext.MessageBox.confirm(t('pim.manual.startimport.pim.title'), t('pim.manual.startimport.single'), function (btn) {
                if (btn == 'yes') {
                    Ext.Ajax.request({
                        url: "/admin/SylphenDataBridge/import/manual-pim-import",
                        method: 'post',
                        params: {
                            dataportId: this.dataportId,
                            rawDataId: records.map(function(record) {
                                return record.get('id')
                            }).join(',')
                        },
                        success: function (response) {
                            response = Ext.decode(response.responseText);

                            if (!(response && response.success)) {
                                opendxp.helpers.showNotification(t("error"), t(response.msg), "error");
                            } else {
                                Ext.create('Ext.window.MessageBox', {
                                    resizable: true,
                                    maximizable: true,
                                    maxWidth: '100%',
                                    maxHeight: '100%',
                                    closeAction: 'destroy',
                                    padding:'0 5'
                                }).show({
                                    title: t("pim.manual.startimport.pim.title"),
                                    msg: t('pim.manual.importForm.success')+'<hr><pre style="white-space:pre-wrap;white-space:-moz-pre-wrap;white-space:-pre-wrap;white-space:-o-pre-wrap">'+response.logs+'</pre>',
                                    icon: Ext.MessageBox.SUCCESS,
                                    buttons: Ext.Msg.OK,
                                    width: '80%',
                                    height: '80%'
                                });
                            }

                        }.bind(this)
                    });
                }
            }.bind(this));
        }.bind(this);

        var hasKeyFields = false;
        Ext.each(columns, function (column) {
            if(column.keyField) {
                hasKeyFields = true;
                return false;
            }
        });

        var columnConfig = [
            {
                xtype: 'actioncolumn',
                width: 60,
                sortable: false,
                locked: true,
                menuDisabled: hasKeyFields,
                hidden: !opendxp.globalmanager.get("user").isAllowed('plugin_sylphen_data_bridge_admin_permission') && !opendxp.globalmanager.get("user").isAllowed('Dataport ' + this.dataportId + ' Execution'),
                items: [
                    {
                        tooltip: t('pim.manual.startimport.pim.title'),
                        icon: "/bundles/opendxpadmin/img/flat-color-icons/import.svg",
                        handler: function (grid, rowIndex) {
                            var record = grid.getStore().getAt(rowIndex);
                            importRawDataItem([record]);
                        }.bind(this)
                    },
                    {
                        tooltip: t('pim.dataport.import.open_objects_by_key_fields'),
                        iconCls: "opendxp_icon_object opendxp_icon_overlay_go opendxp_icon_overlay_actioncolumn",
                        handler: function (grid, rowIndex) {
                            var record = grid.getStore().getAt(rowIndex);
                            Ext.Ajax.request({
                                url: "/admin/SylphenDataBridge/import/get-element-ids/" + record.get('id'),
                                success: function (response) {
                                    try {
                                        response = Ext.decode(response.responseText);

                                        if (response.elementIDs.length === 0) {
                                            opendxp.helpers.showNotification(t("info"), t('pim.dataport.import.no_matching_objects_found'), 'info');
                                        }

                                        Ext.each(response.elementIDs, function (elementId) {
                                            opendxp.helpers.openElement(elementId, response.elementType);
                                        })
                                    } catch (e) {
                                        opendxp.helpers.showNotification(t("error"), t('pim.dataport.import.no_matching_objects_found'), 'error');
                                    }
                                }
                            });
                        }.bind(this)
                    }
                ]
            },{
                header: t('source'),
                dataIndex: 'file',
                width: 200,
                locked: true,
                hidden: fileStore.getCount() <= 1,
                filter: {
                    type: 'list',
                    store: fileStore
                },
                renderer: function(value) {
                    if(value == -1) {
                        return '(Default)';
                    }
                    return value;
                }
            },
            {
                header: t('language'),
                dataIndex: 'locale',
                locked: true,
                filter: {
                    active: false,
                    type: 'list',
                    store: localeStore
                }, renderer: function(value) {
                    if(value == -1) {
                        return '(Default)';
                    }
                    return value;
                },
                hidden: this.sourceType !== 'pimcore'
            },
            {header: t('pim.dataport_rawitemfield_id'), width: 100, dataIndex: 'id', locked: true, hidden: true, sortType: 'asInt', autoSizeColumn: true},
            {header: t('pim.dataport_rawitemfield_updated'), width: 140, dataIndex: 'updated', locked: true, hidden: true, sortType: 'asDate', autoSizeColumn: true}
        ];

        Ext.each(columns, function (column) {
            readerFields.push({name: 'field_' + column.fieldNo, critical: true});
            columnConfig.push({
                header: column.name,
                dataIndex: 'field_' + column.fieldNo,
                locked: column.keyField,
                autoSizeColumn: true,
                sortType: 'asUCText',
                convert: function(v, record){
                    return (v === null) ? '' : v
                },
                renderer: function (value, metaData, record) {
                    if (typeof value === "undefined" || value === null) {
                        value = '';
                    }

                    if (value.length > 10000 && value.indexOf('[DEBUG] ') === -1) {
                        value = value.substr(0, 10000)+' ...';
                    }
                    value = Ext.util.Format.htmlEncode(value);

                    var searchTerm = searchField.getValue().toLowerCase();
                    if (searchTerm) {
                        var searchTerms = [];
                        try {
                            let segmenter = new Intl.Segmenter(opendxp.globalmanager.get("user").language, { granularity: "word" });
                            var segments = segmenter.segment(searchTerm);
                            for (let { segment, isWordLike } of segments) {
                                if (isWordLike) {
                                    searchTerms.push(segment);
                                }
                            }
                        } catch (e) {
                            searchTerms = searchTerm.split(' ');
                        }

                        Ext.Array.each(searchTerms, function (searchTerm) {
                            // preg_quote, see https://stackoverflow.com/a/6829401
                            var searchTermRegex = new RegExp('(' + searchTerm.replace(new RegExp('[.\\\\+*?\\[\\^\\]$(){}=!<>|:\\-]', 'g'), '\\$&') + ')', 'ig');
                            value = value.replace(searchTermRegex, "<span style=\"background-color: yellow\">$1</span>");
                        });
                    }

                    return '<pre style="white-space:pre-wrap;white-space:-moz-pre-wrap;white-space:-pre-wrap;white-space:-o-pre-wrap;margin:0">' + value + '</pre>'
                },
                editor: {
                    xtype: 'textarea'
                }
            });
        });

        var searchQuery = function (field) {
            store.getProxy().setExtraParam("query", field.getValue());
            toolbar.moveFirst();
        };

        var searchField = new Ext.form.TextField(
          {
              name: "query",
              width: 400,
              labelWidth: 50,
              fieldLabel: t('search'),
              enableKeyEvents: true,
              triggers: {
                  search: {
                      weight: 1,
                      cls: 'x-form-search-trigger',
                      handler: function (field, trigger, e) {
                          searchQuery(field);
                      }.bind(this)
                  }
              },
              listeners: {
                  keyup: function (field, key) {
                      if (key.getKey() == key.ENTER) {
                          searchQuery(field);
                      }
                  }.bind(this)
              }
          }
        );

        var store = Ext.create('Ext.data.JsonStore', {
            proxy: {
                type: 'ajax',
                api: {
                    read: '/admin/SylphenDataBridge/importconfig/get-rawdata/' + this.dataportId,
                    create: '/admin/SylphenDataBridge/importconfig/create-raw-data/' + this.dataportId,
                    update: '/admin/SylphenDataBridge/importconfig/update-raw-data/' + this.dataportId,
                    destroy: '/admin/SylphenDataBridge/importconfig/delete-rawdata',
                },
                reader: {
                    type: 'json',
                    rootProperty: 'fields',
                    messageProperty: 'message'
                },
                filterParam: 'filter'
            },

            idProperty: 'id',
            fields: readerFields,
            remoteFilter: true,
            remoteSort: true,
            autoSync: true,
            pageSize: 25,
            listeners: {
                write: function() {
                    this.mappingPanel.getStore().load();
                    this.grid.updateLayout();
                }.bind(this)
            }
        });

        var toolbar = opendxp.helpers.grid.buildDefaultPagingToolbar(store, {pageSize: 25});


        this.grid = new Ext.grid.Panel({
            border: false,
            frame: false,
            store: store,
            multiSelect: true,
            columnLines: true,
            stripeRows: true,
            multiColumnSort: true,
            columns: columnConfig,
            plugins: [
                {
                    ptype: 'cellediting',
                    clicksToEdit: 2
                },
                'gridfilters'
            ],
            dockedItems: [{
                dock: 'top',
                xtype: 'toolbar',
                items: [
                    searchField,
                    '->',
                    {
                        xtype: 'button',
                        text: t('add'),
                        hidden: !opendxp.globalmanager.get("user").isAllowed('plugin_sylphen_data_bridge_admin_permission') && !opendxp.globalmanager.get("user").isAllowed('Dataport ' + this.dataportId + ' Execution'),
                        iconCls: 'opendxp_icon_add',
                        handler: function () {
                            var u = new store.model();
                            store.add(u);

                            this.grid.getView().focusRow(u);
                            this.grid.ensureVisible(u, {select: true});
                        }.bind(this)
                    },
                    {
                        xtype: 'button',
                        text: t('pim.delete_rawdata'),
                        hidden: !opendxp.globalmanager.get("user").isAllowed('plugin_sylphen_data_bridge_admin_permission') && !opendxp.globalmanager.get("user").isAllowed('Dataport ' + this.dataportId + ' Execution'),
                        iconCls: 'opendxp_icon_delete',
                        handler: function () {
                            Ext.MessageBox.confirm(t('pim.delete_rawdata'), t('pim.delete_rawdata.confirm'), function (btn) {
                                if (btn === 'yes') {
                                    Ext.Ajax.request({
                                        url: "/admin/SylphenDataBridge/import/delete-rawdata",
                                        params: {
                                            dataportId: self.dataportId
                                        },

                                        success: function(response, opts) {
                                            var obj = Ext.decode(response.responseText);
                                            if (obj.success) {
                                                self.grid.getStore().load();

                                                if(obj.runningProcesses) {
                                                    Ext.MessageBox.confirm(t('info'), t('pim.manual.delete.rawdata.running_process_rawdata'), function (btn) {
                                                        if (btn == 'yes') {
                                                            Ext.Ajax.request({
                                                                url: "/admin/SylphenDataBridge/import/delete-rawdata",
                                                                params: {
                                                                    dataportId: self.dataportId,
                                                                    force: 1
                                                                },
                                                                success: function (response, opts) {
                                                                    var obj = Ext.decode(response.responseText);
                                                                    if (obj.success) {
                                                                        self.grid.getStore().load();
                                                                    } else {
                                                                        Ext.MessageBox.alert(t('error'), (typeof obj.message !== "undefined") ? obj.message : t('pim.manual.delete.rawdata.failure'));
                                                                    }
                                                                }
                                                            });
                                                        }
                                                    });
                                                }
                                            } else {
                                                Ext.MessageBox.alert(t('error'), (typeof obj.message !== "undefined") ? obj.message : t('pim.manual.delete.rawdata.failure'));
                                            }
                                        },

                                        failure: function(response, opts) {
                                            Ext.MessageBox.alert(t('error'), t('pim.manual.delete.rawdata.failure'));
                                        }
                                    });
                                }
                            });
                        }
                    }
                ]
            }],
            bbar: toolbar,

            listeners: {
                render: function(grid, eOpts) {
                    var panels = [grid.getLockingViewConfig().locked, grid.getLockingViewConfig().normal];
                    for(var i in panels) {
                        panels[i].getHeaderContainer().on('menucreate', function (container, menu, eOpts) {
                            var sibling, index;
                            // if we allow multi sorting and this column is sortable
                            if (grid.multiColumnSort && container.sortable) {
                                sibling = menu.down('#descItem');
                                index = menu.items.indexOf(sibling);
                                menu.insert(index + 1, {
                                    itemId: 'removeSortItem',
                                    text: t('pim.preview.remove_column_sort'),
                                    handler: function (button, e, eOpts) {
                                        var column = menu.activeHeader;
                                        var sorters = store.getSorters();
                                        // remove from sorters
                                        sorters.removeByKey(column.dataIndex);
                                        // update sort state
                                        column.sortState = null;
                                        column.removeCls([column.ascSortCls, column.descSortCls]);
                                        store.load();
                                    }.bind(this)
                                });
                            }

                            menu.add({
                                text: t('pim.preview.minimize_column_width'),
                                iconCls: 'opendxp_icon_table_col',
                                handler: function(menuItem) {
                                    Ext.suspendLayouts();
                                    Ext.each(this.grid.getColumns(), function (column) {
                                        if(column.autoSizeColumn) {
                                            if (this.columnsCollapsed) {
                                                column.autoSize();
                                            } else {
                                                if (column.getWidth() > 100) {
                                                    column.setWidth(100);
                                                }
                                            }
                                        }
                                    }.bind(this));
                                    Ext.resumeLayouts(true);

                                    this.columnsCollapsed = !this.columnsCollapsed;

                                    if(this.columnsCollapsed) {
                                        menuItem.setText(t('pim.preview.maximize_column_width'));
                                    } else {
                                        menuItem.setText(t('pim.preview.minimize_column_width'));
                                    }
                                }.bind(this)
                            });
                        }.bind(this));
                    }
                }.bind(this),
                beforeedit: function (editor, e) {
                    if (!opendxp.globalmanager.get("user").isAllowed('plugin_sylphen_data_bridge_admin_permission') && !opendxp.globalmanager.get("user").isAllowed('Dataport ' + this.dataportId + ' Execution')) {
                        return false;
                    }

                    var editorField = e.column.getEditor();
                    if (editorField.isXType('textarea')) {
                        editorField.setHeight(Ext.fly(e.row).getHeight());
                    }
                }.bind(this),
                itemcontextmenu: function(grid, record, item, index, e) {
                    var menu = new Ext.menu.Menu();

                    menu.add(new Ext.menu.Item({
                        text: t('pim.dataport.import.open_objects_by_key_fields'),
                        iconCls: "opendxp_icon_object opendxp_icon_overlay_go",
                        handler: function() {
                            var records = grid.getSelectionModel().getSelection();
                            Ext.each(records, function (record) {
                                Ext.Ajax.request({
                                    url: "/admin/SylphenDataBridge/import/get-element-ids/" + record.get('id'),
                                    success: function (response) {
                                        try {
                                            response = Ext.decode(response.responseText);

                                            if (response.elementIDs.length === 0) {
                                                opendxp.helpers.showNotification(t("info"), t('pim.dataport.import.no_matching_objects_found'), 'info');
                                            }

                                            Ext.each(response.elementIDs, function (elementId) {
                                                opendxp.helpers.openElement(elementId, response.elementType);
                                            })
                                        } catch (e) {
                                            opendxp.helpers.showNotification(t("error"), t('pim.dataport.import.no_matching_objects_found'), 'error');
                                        }
                                    }
                                });
                            });
                        }
                    }));

                    menu.add(new Ext.menu.Item({
                        text: t('pim.manual.startimport.pim.title'),
                        icon: "/bundles/opendxpadmin/img/flat-color-icons/import.svg",
                        handler: function() {
                            var records = grid.getSelectionModel().getSelection();
                            importRawDataItem(records);
                        }
                    }));

                    menu.add(new Ext.menu.Item({
                        text: t('pim.preview.minimize_column_width'),
                        icon: "/bundles/opendxpadmin/img/flat-color-icons/tag.svg",
                        handler: function(menuItem) {
                            Ext.suspendLayouts();
                            Ext.each(this.grid.getColumns(), function (column) {
                                if (column.autoSizeColumn) {
                                    if (this.columnsCollapsed) {
                                        column.autoSize();
                                    } else {
                                        if (column.getWidth() > 100) {
                                            column.setWidth(100);
                                        }
                                    }
                                }
                            }.bind(this));
                            Ext.resumeLayouts(true);

                            this.columnsCollapsed = !this.columnsCollapsed;

                            if (this.columnsCollapsed) {
                                menuItem.setText(t('pim.preview.maximize_column_width'));
                            } else {
                                menuItem.setText(t('pim.preview.minimize_column_width'));
                            }
                        }.bind(this)
                    }));

                    menu.add(new Ext.menu.Item({
                        text: t('pim.delete_rawdata'),
                        icon: "/bundles/opendxpadmin/img/flat-color-icons/delete.svg",
                        handler: function() {
                            Ext.MessageBox.confirm(t('pim.delete_rawdata'), t('pim.delete_rawdata.confirm'), function (btn) {
                                if (btn === 'yes') {
                                    var rec = grid.getSelectionModel().getSelection();
                                    if (record) {
                                        var clickedRecordIsSelected = false;
                                        Ext.each(rec, function (selectedRecord) {
                                            if (selectedRecord === record) {
                                                clickedRecordIsSelected = true;
                                                return false;
                                            }
                                        });
                                    } else {
                                        clickedRecordIsSelected = true;
                                    }

                                    if (!clickedRecordIsSelected) {
                                        rec = [record];
                                    }

                                    store.remove(rec);
                                }
                            });
                        }
                    }));

                    menu.showAt(e.getXY());

                    e.stopEvent();
                }.bind(this)
            },
            viewConfig: {
                listeners: {
                    refresh: function(dataview) {
                        if(dataview.panel.columns.length < 30) {
                            this.grid.suspendLayouts();
                            Ext.each(dataview.panel.columns, function (column) {
                                if (!this.columnsCollapsed && column.autoSizeColumn === true) {
                                    column.autoSize();
                                }
                            });
                            this.grid.resumeLayouts(true);

                            if(!this.columnsCollapsed) {
                                var totalWidth = 0;
                                Ext.each(dataview.panel.columns, function (column) {
                                    totalWidth += column.getWidth();
                                });

                                if (totalWidth < this.grid.getWidth()) {
                                    Ext.each(dataview.panel.columns, function (column) {
                                        if (column.autoSizeColumn === true) {
                                            column.setWidth(Math.floor(this.grid.getWidth() / dataview.panel.columns.length));
                                        }
                                    }.bind(this));
                                }
                            }
                        }
                    }.bind(this)
                },
                enableTextSelection: true,
                emptyText: t('pim.preview.no_records')+'. <a href="#" onclick="var manualPanel=opendxp.globalmanager.get(\'data-bridge\').getOpenPanels(\'' + this.dataportId + '\')[0].manualPanel;opendxp.globalmanager.get(\'action_pim_importconfig\').getOpenPanels(\'' + this.dataportId + '\')[0].setActiveItem(manualPanel);manualPanel.getComponent(\'toolbar\').getComponent(\'start_rawdata_button\').fireHandler();">'+t('pim.manual.importForm.start')+'</a>'
            }
        });

        this.add(this.grid);

        this.on('activate', function() {
            store.load();
        });
    }
});