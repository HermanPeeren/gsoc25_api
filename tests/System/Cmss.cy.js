describe('CCM CMS Admin', () => {
  beforeEach(() => {
    cy.db_createCms({ name: 'Test CMS' });
    cy.doAdministratorLogin();
    cy.visit('/administrator/index.php?option=com_ccm&view=cmss');
  });

  afterEach(() => {
    cy.task('queryDB', "DELETE FROM #__ccm_cms WHERE name = 'Test CMS'");
  });

  it('shows the CMSs list view', () => {
    cy.get('h1').should('contain.text', ' CMS Names List');
    cy.get('table').should('exist');
  });

  it('can search for a CMS with name', () => {
    cy.get('input[id="filter_search"]').clear().type('Test');
    cy.get('button[type="submit"]').click();
    cy.get('table').contains('Test CMS').click();
  });

  it('can open a CMS', () => {
    cy.get('table').contains('Test CMS').click();
    cy.url().should('include', 'option=com_ccm&view=cms&layout=edit');
    cy.get('form').should('exist');
    cy.get('h1').should('contain.text', 'Edit CMS');
  });
});