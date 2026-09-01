/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


opendxp.registerNS("opendxp.layout.portlets.DataBridge_QueueMonitor");
opendxp.layout.portlets.DataBridge_QueueMonitor = Class.create(opendxp.layout.portlets.abstract, {

    getType: function () {
        return "opendxp.layout.portlets.DataBridge_QueueMonitor";
    },

    getName: function () {
        return t('pim.portlets.queue-monitor');
    },

    getIcon: function () {
        return "opendxp_icon_data_bridge";
    },

    getLayout: function (portletId) {
        var defaultConf = this.getDefaultConfig();
        defaultConf.tools = [
            {
                type: 'close',
                handler: this.remove.bind(this)
            }
        ];

        this.layout = Ext.create('Portal.view.Portlet', Object.assign(defaultConf, {
            title: this.getName(),
            iconCls: this.getIcon(),
            layout: "fit",
            items: [
                {
                    xtype: "component",
                    autoEl: {
                        tag: "iframe",
                        src: "/admin/SylphenDataBridge/import/start-queue-processing"
                    },
                    border: false
                }
            ]
        }));

        this.layout.portletId = portletId;
        return this.layout;
    },
});