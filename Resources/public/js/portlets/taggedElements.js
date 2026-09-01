/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


opendxp.registerNS("opendxp.layout.portlets.DataBridge_TaggedElements");
opendxp.layout.portlets.DataBridge_TaggedElements = Class.create(opendxp.layout.portlets.abstract, {
    config: {},

    getType: function () {
        return "opendxp.layout.portlets.DataBridge_TaggedElements";
    },

    getName: function () {
        return this.config.title || t('pim.portlets.tagged-elements');
    },

    getIcon: function () {
        return "opendxp_icon_element_tags";
    },

    setConfig: function (config) {
        var parsed = {
            title: '',
            tags: []
        };

        try {
            if (config) {
                parsed = JSON.parse(config);
            }
        } catch (e) {
            console.error('Failed to parse IframePortlet widget config: ', e);
        }

        this.config = parsed;
    },

    editSettings: function () {
        var config = this.config || {};

        var tree = new opendxp.element.tag.tree();
        tree.setAllowAdd(false);
        tree.setAllowDelete(false);
        tree.setAllowDnD(false);
        tree.setAllowRename(false);
        tree.setShowSelection(true);

        tree.getLayout().on('load', function() {
            Ext.each(tree.getLayout().getStore().queryBy(function () { return true; }).getRange(), function(record) {
                if(this.config.tags.indexOf(record.get('id')) > -1) {
                    record.set('checked', 1);
                }
            }.bind(this));
        }.bind(this));

        var portletTitle = Ext.create('Ext.form.field.Text', {
            name: 'title',
            fieldLabel: t('title'),
            value: config.title || '',
            padding: '0 0 0 11',
            labelStyle: 'font-size:16px'
        });

        var win = new Ext.Window({
            width: '80%',
            height: '80%',
            bodyStyle: "padding: 10px",
            modal: true,
            title: t('filter_tags'),
            closeAction: "destroy",
            buttons: [{
                text: t('save'),
                iconCls: "opendxp_icon_save",
                handler: function () {
                    this.updateSettings({
                        title: portletTitle.getValue(),
                        tags: tree.getCheckedTagIds()
                    });
                    win.close();
                }.bind(this)
            }],
            items: [
                portletTitle,
                {
                    xtype: "form",
                    title: t('tags')+':',
                    items: [
                        tree.getLayout()
                    ]
                }
            ]
        });
        win.show();
    },

    updateSettings: function (data) {
        this.config = data;

        Ext.Ajax.request({
            url: Routing.generate('opendxp_admin_portal_updateportletconfig'),
            method: 'PUT',
            params: {
                key: this.portal.key,
                id: this.layout.portletId,
                config: JSON.stringify(this.config)
            },
            success: function () {
                this.layout.setTitle(data.title);
                this.store.proxy.setExtraParam("tagIds[]", data.tags);
                this.store.load();
            }.bind(this)
        });
    },

    getLayout: function (portletId) {
        this.store = new Ext.data.Store({
            autoDestroy: true,
            remoteSort: true,
            pageSize: 25,
            proxy: {
                type: 'ajax',
                url: Routing.generate('opendxp_bundle_search_search_find'),
                reader: {
                    type: 'json',
                    rootProperty: 'data'
                },
                extraParams: {
                    type: 'object',
                    "tagIds[]": this.config.tags
                }
            },
            fields: ["id", "fullpath", "type", "modificationDate"]
        });

        this.store.load();

        var toolbar = opendxp.helpers.grid.buildDefaultPagingToolbar(this.store, { pageSize: 25 });

        var grid = Ext.create('Ext.grid.Panel', {
            store: this.store,
            columns: [
                { text: t('path'), sortable: false, dataIndex: 'fullpath', flex: 1 },
                {
                    text: t('modificationdate'), width: 150, sortable: false, renderer: function (d) {
                        var date = new Date(d * 1000);
                        return Ext.Date.format(date, "Y-m-d H:i:s");
                    }, dataIndex: 'modificationDate'
                }

            ],
            stripeRows: true,
            autoExpandColumn: 'path',
            bbar: toolbar
        });

        grid.on("rowclick", function (grid, record, tr, rowIndex, e, eOpts) {
            var data = grid.getStore().getAt(rowIndex);

            opendxp.helpers.openObject(data.data.id, data.data.type);
        });

        var defaultConf = this.getDefaultConfig();
        defaultConf.tools = [
            {
                type: 'gear',
                handler: this.editSettings.bind(this)
            },
            {
                type: 'close',
                handler: this.remove.bind(this)
            }
        ];

        this.layout = Ext.create('Portal.view.Portlet', Object.assign(defaultConf, {
            title: this.getName(),
            iconCls: this.getIcon(),
            height: 275,
            layout: "fit",
            items: [grid]
        }));

        this.layout.portletId = portletId;
        return this.layout;
    }
});