/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


opendxp.registerNS("opendxp.layout.portlets.DataBridge_ErrorMonitor");
opendxp.layout.portlets.DataBridge_ErrorMonitor = Class.create(opendxp.layout.portlets.abstract, {
    statusGrid: null,

    getType: function () {
        return "opendxp.layout.portlets.DataBridge_ErrorMonitor";
    },

    getName: function () {
        return t('pim.portlets.error-monitor');
    },

    getIcon: function () {
        return "opendxp_icon_error";
    },

    getLayout: function (portletId) {
        var defaultConf = this.getDefaultConfig();
        defaultConf.tools = [
            {
                type: 'close',
                handler: this.remove.bind(this)
            }
        ];

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
            { name: 'worstLogType' },
            { name: 'worstLog' },
            { name: 'triggeredBy' },
            { name: 'dataportName' }
        ]

        if (typeof JSONImportModel === "undefined") {
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
                url: '/admin/SylphenDataBridge/import/get-status?search[]=errors&' + ((typeof Intl !== "undefined") ? '&timezone=' + Intl.DateTimeFormat().resolvedOptions().timeZone : ''),
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
                            this.timeout = setTimeout(function () {
                                if (self.statusGrid.isVisible(true) && store.currentPage === 1) {
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

        this.statusGrid = Ext.create('Ext.grid.Panel', {
            flex: 1,
            store: store,
            plugins: ['gridfilters'],
            columns: [
                { header: 'id', dataIndex: 'id', hidden: true },
                {
                    header: t('pim.manual.statusgrid.dataport'),
                    width: 150,
                    dataIndex: 'dataportName'
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
                    }, renderer: function (value, meta, record) {
                        var file = value;
                        if (value == -1) {
                            file = '(' + t('unknown') + ')';
                        }

                        var sourceElementIds = record.get('sourceElementIDs');
                        if (sourceElementIds.length === 0) {
                            return file;
                        }

                        var urls = [];
                        Ext.each(sourceElementIds, function (sourceElementId) {
                            urls.push('javascript:opendxp.helpers.openElement(' + sourceElementId + ', \'asset\');');
                        });

                        meta.tdAttr = 'data-qtip="' + t('pim.manual.statusgrid.click_to_open_archive_file') + '"';

                        return Ext.String.format('<a href="{0}">{1}</a>', urls.join(';'), file);
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
                    }, renderer: function (value) {
                        if (value == -1) {
                            return '(' + t('unknown') + ')';
                        }
                        return value;
                    },
                    hidden: this.sourceType !== 'pimcore'
                },
                {
                    header: t('pim.manual.statusgrid.type'),
                    width: 160,
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

                        if (record.get('logFile') && opendxp.globalmanager.get("user").isAllowed('application_logging')) {
                            return Ext.String.format('<a href="{0}" target="_blank">{1}</a>', '/admin/SylphenDataBridge/import/log/' + record.get('logFile'), status);
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

        this.layout = Ext.create('Portal.view.Portlet', Object.assign(defaultConf, {
            title: this.getName(),
            iconCls: this.getIcon(),
            layout: "fit",
            items: [
                this.statusGrid
            ]
        }));

        this.layout.portletId = portletId;
        return this.layout;
    },
});