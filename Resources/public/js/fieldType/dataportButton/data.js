/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


opendxp.registerNS("opendxp.object.classes.layout.dd_button");
opendxp.object.classes.layout.dd_button = Class.create(opendxp.object.classes.layout.layout, {
    type: "dd_button",

    initialize: function (treeNode, initData) {
        this.type = "dd_button";

        this.initData(initData);

        this.treeNode = treeNode;
    },

    getTypeName: function () {
        return t("dd_button");
    },

    getIconClass: function () {
        return "opendxp_icon_button";
    },

    getLayout: function () {
        var dataportStore = Ext.create('Ext.data.JsonStore', {
            fields: ['id', 'name', 'description', 'icon', 'url', 'group', 'groupIcon', 'searchField'],
            proxy: {
                type: 'ajax',
                url: '/admin/SylphenDataBridge/importconfig/can-be-executed',
                extraParams: {
                    classId: this.treeNode.getOwnerTree().getRootNode().data.classId,
                    className: this.treeNode.getOwnerTree().getRootNode().data.className
                },
                reader: {
                    type: 'json',
                    rootProperty: 'dataports',
                    transform: function (data) {
                        var dataports = [];
                        Ext.each(data.dataports.imports, function (dataport) {
                            dataport.group = t('import');
                            dataport.groupIcon = '/bundles/opendxpadmin/img/flat-color-icons/import.svg';

                            dataport.searchField = dataport.name + ' ' + dataport.id;

                            dataports.push(dataport);
                        });

                        Ext.each(data.dataports.exports, function (dataport) {
                            dataport.group = t('export');
                            dataport.groupIcon = '/bundles/opendxpadmin/img/flat-color-icons/export.svg';

                            dataport.searchField = dataport.name + ' ' + dataport.id;

                            dataports.push(dataport);
                        });

                        return dataports;
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
            name: 'dataportId',
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
            matchFieldWidth: false,
            minChars: 1,
            emptyText: t('loading') + ' ...',
            listConfig: {
                tpl: [
                    '<tpl for=".">',
                    '{[xindex === 1 || parent[xindex - 2].group !== values.group ? "<div style=\'padding: 5px 0;\'><img src=\'"+values.groupIcon+"\' alt=\'"+values.group+"\' style=\'vertical-align: middle\'> "+values.group+"</div>" : ""]}',
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
            value: this.datax.dataportId
        });

        this.layout = new Ext.Panel({
            title: '<b>' + this.getTypeName() + '</b>',
            bodyStyle: 'padding: 10px;',
            autoScroll: true,
            items: [
                {
                    xtype: "form",
                    bodyStyle: "padding: 10px;",
                    autoScroll: true,
                    style: "margin: 10px 0 10px 0",
                    items: [
                        {
                            xtype: "textfield",
                            fieldLabel: t("name"),
                            name: "name",
                            enableKeyEvents: true,
                            value: this.datax.name
                        },
                        {
                            xtype: "textfield",
                            fieldLabel: t("text"),
                            name: "text",
                            value: this.datax.text
                        },
						{
							xtype: "textfield",
							fieldLabel: t("icon"),
							name: "icon",
							value: this.datax.icon,
							enableKeyEvents: true,
							listeners: {
								"keyup": function (el) {
									el.inputEl.applyStyles("background:url(" + el.getValue() + ") right center no-repeat;");
								},
								"afterrender": function (el) {
									el.inputEl.applyStyles("background:url(" + el.getValue() + ") right center no-repeat;");
								}
							},
                            width: 600
						},
                        dataportField,
                        {
                            xtype: "textfield",
                            fieldLabel: t("width"),
                            name: "width",
                            value: this.datax.width
                        },
                        {
                            xtype: "displayfield",
                            hideLabel: true,
                            value: t('width_explanation')
                        },
                        {
                            xtype: "textfield",
                            fieldLabel: t("height"),
                            name: "height",
                            value: this.datax.height
                        },
                        {
                            xtype: "displayfield",
                            hideLabel: true,
                            value: t('height_explanation')
                        }
                    ]
                }
            ]
        });


        return this.layout;
    }
});
