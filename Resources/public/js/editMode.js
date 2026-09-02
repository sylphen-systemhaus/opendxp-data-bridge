/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH & Co. KG — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


if (typeof opendxp.document.editables !== "undefined") {
    if (typeof opendxp.document.editables.wysiwyg.defaultEditorConfig !== 'object') {
        opendxp.document.editables.wysiwyg.defaultEditorConfig = {};
    }
    opendxp.document.editables.wysiwyg.defaultEditorConfig = mergeObject({ versionCheck: false }, opendxp.document.editables.wysiwyg.defaultEditorConfig);
}

var selectedEditable = null;
document.addEventListener("click", function (e) {
    const target = e.target.closest(".site .opendxp_editable:not(.opendxp_editable_areablock)");

    if (selectedEditable) {
        selectedEditable.style.borderColor = 'transparent';
        selectedEditable = null;
    }

    if (target) {
        target.style.borderColor = 'black';
        selectedEditable = target;
    }
});

function refreshEditables() {
    if (this.config['controlsTrigger'] === 'click') {
        Ext.getBody().on('click', function (event) {
            if (Ext.get(id) && !Ext.get(id).isAncestor(event.target)) {

                try {
                    Ext.get(id).query('.opendxp_area_buttons', false).forEach(function (el) {
                        el.hide();
                    });
                } catch (e) {}
            }
        });
    }

    var activeBlockEl;

    for (var i = 0; i < this.elements.length; i++) {
        if (this.config['controlsTrigger'] === 'click') {
            Ext.get(this.elements[i]).on('click', function (event) {
                let component = Ext.get(event.target);
                if (!component.hasCls('.opendxp_block_entry')) {
                    component = component.up('.opendxp_block_entry');
                }
                if (Ext.dd.DragDropMgr.dragCurrent) {
                    return;
                }

                Ext.get(this.id).query('.opendxp_area_buttons', false).forEach(function (el) {
                    if (component != el.dom) {
                        el.hide();
                    }
                });

                var buttonContainer = Ext.get(component).selectNode('.opendxp_area_buttons', false);
                buttonContainer.show();

                if (activeBlockEl != component) {
                    Ext.menu.Manager.hideAll();
                }
                activeBlockEl = component;
            }.bind(this));
        }
    }
}

var areaBlockRefresh;
if(typeof opendxp.document.editables.areablock.prototype.refresh === "undefined") {
    areaBlockRefresh = opendxp.document.editables.areablock.prototype.applyFallbackIcons;
    opendxp.document.editables.areablock.prototype.applyFallbackIcons = function () {
        areaBlockRefresh.call(this);

        setTimeout(function () {refreshEditables.call(this)}.bind(this), 3000);
    };
} else {
    areaBlockRefresh = opendxp.document.editables.areablock.prototype.refresh;
    opendxp.document.editables.areablock.prototype.refresh = function () {
        areaBlockRefresh.call(this);

        refreshEditables.call(this);
    };
}
