/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


opendxp.registerNS("opendxp.object.layout.dd_htmlContainer");
opendxp.object.layout.dd_htmlContainer = Class.create(opendxp.object.abstract, {

    initialize: function (config, context) {
        this.config = config;
        this.context = context;
    },

    getLayout: function () {
        this.component = new Ext.container.Container({
            border: this.config.border,
            style: this.config.bodyStyle,
            cls: this.config.class,
            scrollable: true,
            html: this.config.html
        });
        return this.component;

    }
});
