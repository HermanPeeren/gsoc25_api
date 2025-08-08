describe('CCM CMS Admin', () => {
  beforeEach(() => {
    cy.db_createCms({ name: 'Test CMS', authentication: '{}' });
    cy.doAdministratorLogin();
    cy.visit('/administrator/index.php?option=com_ccm&view=cmss');
  });

  afterEach(() => {
    cy.task('queryDB', "DELETE FROM #__ccm_cms WHERE name = 'Test CMS'");
  });

  it('shows the correct page title', () => {
    cy.get('h1').should('contain.text', 'Content Management Systems');
  });

  it('shows the CMSs list view with proper table structure', () => {
    cy.get('table').should('exist');
    cy.get('table').should('have.class', 'table-striped');
    cy.get('thead th').should('contain.text', 'CMS Name');
    cy.get('thead th').should('contain.text', 'ID');
  });

  it('has the proper toolbar buttons', () => {
    cy.get('.btn-toolbar').should('exist');
    cy.get('button').contains('New').should('exist');
  });

  it('can search for a CMS with name', () => {
    cy.get('input[id="filter_search"]').clear().type('Test');
    cy.get('button[type="submit"]').click();
    cy.get('table').contains('Test CMS').should('exist');
  });

  it('can open a CMS for editing', () => {
    cy.get('table').contains('Test CMS').click();
    cy.url().should('include', 'option=com_ccm&view=cms&layout=edit');
    cy.get('form').should('exist');
    cy.get('h1').should('contain.text', 'Edit CMS');
  });

  it('displays empty state when no results found', () => {
    cy.get('input[id="filter_search"]').clear().type('NonExistentCMS');
    cy.get('button[type="submit"]').click();
    cy.get('.alert-info').should('contain.text', 'No Matching Results');
  });
});