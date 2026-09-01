/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


opendxp.registerNS("opendxp.plugin.Pim.ImportConfig");
opendxp.plugin.Pim.VersionPanel = Ext.extend(Ext.Panel, {
    initComponent: function () {
        var store = new Ext.data.JsonStore({
            proxy: {
                type: 'ajax',
                url: '/admin/SylphenDataBridge/importconfig/get-versions/' + this.dataportId,
                reader: {
                    type: 'json',
                    rootProperty: 'versions',
                    messageProperty: 'errorMessage'
                }
            },
            fields: ['date', 'user', 'timestamp'],
            autoLoad: true
        });

        var versionPanel = Ext.create('Ext.grid.Panel', {
            store: store,
            plugins: ['gridfilters'],
            columns: [
                {
                    header: t('date'),
                    width: 150,
                    dataIndex: 'date',
                    xtype: 'datecolumn',
                    format: 'd.m.Y H:i',
                    renderer: function (value) {
                        return value;
                    }
                },
                { text: t("user"), dataIndex: 'user', filter: 'list', flex: 1 }
            ],
            stripeRows: true,
            width: 300,
            border: true,
            region: "west",
            resizable: true,
            scrollable: true,
            viewConfig: {
                enableTextSelection: true
            },
            listeners: {
                rowclick: function(grid, record) {
                    Ext.Ajax.request({
                        url: '/admin/SylphenDataBridge/importconfig/compare-version/'+this.dataportId+'_'+ record.get('timestamp'),
                        success: function (response) {
                            response = Ext.decode(response.responseText);

                            if (typeof response.result !== 'undefined') {
                                preview.setHtml(response.result);
                            } else {
                                Ext.MessageBox.alert(t('error'), response.msg);
                            }
                        }.bind(this)
                    });
                }.bind(this),
                rowcontextmenu: function(grid, record, tr, rowIndex, e) {
                    var menu = new Ext.menu.Menu();

                    menu.add(new Ext.menu.Item({
                        text: t('restore'),
                        iconCls: "opendxp_icon_reverseObjectRelation",
                        handler: function() {
                            Ext.Ajax.request({
                                url: '/admin/SylphenDataBridge/importconfig/restore-version/' + this.dataportId + '_' + record.get('timestamp'),
                                success: function (response) {
                                    response = Ext.decode(response.responseText);

                                    if (typeof response.result !== 'undefined' && response.result) {
                                        this.dataportPanel.versionWindow.close();
                                        this.dataportPanel.importConfigPanel.removeOpenPanel(this.dataportId);
                                        this.dataportPanel.importConfigPanel.id = this.dataportId;
                                        this.dataportPanel.importConfigPanel.onTreeNodeClick();
                                    } else {
                                        Ext.MessageBox.alert(t('error'), response.msg);
                                    }
                                }.bind(this)
                            });
                        }.bind(this)
                    }));

                    e.stopEvent();
                    menu.showAt(e.pageX, e.pageY);
                }.bind(this)
            }
        });

        Ext.Ajax.request({
            url: '/admin/SylphenDataBridge/importconfig/get-config-path',
            success: function (response) {
                response = Ext.decode(response.responseText);

                if (typeof response.result !== 'undefined' && response.result) {
                    preview.setHtml(t("pim.versions.compare_with_current")+'<hr>'+ t("pim.versions.add_to_git").replace('%s', response.path));
                }
            }.bind(this)
        });

        var preview = new Ext.Panel({
            html: t("pim.versions.compare_with_current"),
            padding: 10,
            region: 'center',
            autoScroll: true
        });

        Ext.apply(this, {
            xtype: 'panel',
            layout: 'border',
            items: [versionPanel, preview]
        });

        opendxp.plugin.Pim.VersionPanel.superclass.initComponent.call(this);

        // show the panels by refreshing
        opendxp.layout.refresh();
    }
});